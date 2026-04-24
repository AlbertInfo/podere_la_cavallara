#!/usr/bin/env python3
import argparse
import base64
import csv
import json
import mimetypes
import os
import re
import sys
import time
import unicodedata
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import urlencode

import requests
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding


TOKEN_SCOPE = "https://www.googleapis.com/auth/cloud-platform"
DEFAULT_TOKEN_URI = "https://oauth2.googleapis.com/token"


class OcrError(Exception):
    pass


@dataclass
class Country:
    code: str
    description: str
    normalized: str


@dataclass
class Comune:
    code: str
    description: str
    province: str
    label: str
    data_fine: str
    normalized_description: str
    normalized_label: str


def normalize(value: str) -> str:
    value = str(value or "").strip()
    if not value:
        return ""
    value = unicodedata.normalize("NFD", value)
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    value = value.upper()
    value = re.sub(r"[’'`.,;:/\\\-_()\[\]{}\"]", " ", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def title_case(value: str) -> str:
    value = re.sub(r"\s+", " ", str(value or "").strip())
    if not value:
        return ""
    lowered = value.lower()
    titled = lowered.title()
    titled = re.sub(r"\bD([A-Z])\b", lambda m: "D'" + m.group(1), titled)
    return titled


def parse_csv(path: Path) -> List[dict]:
    rows: List[dict] = []
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            rows.append({k: (v or "").strip() for k, v in row.items()})
    return rows


def load_countries(data_dir: Path) -> List[Country]:
    rows = parse_csv(data_dir / "stati.csv")
    out: List[Country] = []
    for row in rows:
        code = row.get("Codice", "")
        desc = row.get("Descrizione", "")
        data_fine = row.get("DataFineVal", "")
        if not code or not desc or data_fine:
            continue
        out.append(Country(code=code, description=desc, normalized=normalize(desc)))
    return out


def load_comuni(data_dir: Path) -> List[Comune]:
    rows = parse_csv(data_dir / "comuni.csv")
    out: List[Comune] = []
    for row in rows:
        code = row.get("Codice", "")
        desc = row.get("Descrizione", "")
        province = row.get("Provincia", "")
        if not code or not desc:
            continue
        label = f"{desc} ({province})" if province else desc
        out.append(
            Comune(
                code=code,
                description=desc,
                province=province,
                label=label,
                data_fine=row.get("DataFineVal", ""),
                normalized_description=normalize(desc),
                normalized_label=normalize(label),
            )
        )
    return out


def find_country(countries: List[Country], value: str) -> Optional[Country]:
    value = str(value or "").strip()
    if not value:
        return None

    aliases = {
        "ITALIANA": "ITALIA",
        "ITALIAN": "ITALIA",
        "ITALY": "ITALIA",
        "ROMANIA": "ROMANIA",
        "UNITED STATES": "STATI UNITI D'AMERICA",
        "USA": "STATI UNITI D'AMERICA",
        "U S A": "STATI UNITI D'AMERICA",
        "UNITED KINGDOM": "REGNO UNITO",
        "GREAT BRITAIN": "REGNO UNITO",
        "UK": "REGNO UNITO",
    }

    normalized = normalize(value)
    normalized = normalize(aliases.get(normalized, normalized))

    for country in countries:
        if value == country.code or normalized == country.normalized:
            return country
    return None


def find_comune(comuni: List[Comune], value: str, province: str = "") -> Optional[Comune]:
    value = str(value or "").strip()
    province = str(province or "").strip().upper()
    if not value:
        return None

    for comune in comuni:
        if value == comune.code:
            return comune

    normalized_value = normalize(value)
    normalized_province = normalize(province)
    matches: List[Comune] = []
    for comune in comuni:
        if normalized_value == comune.normalized_label:
            matches.append(comune)
            continue
        if normalized_value == comune.normalized_description:
            if not normalized_province or normalized_province == normalize(comune.province):
                matches.append(comune)

    if not matches:
        # try stripping province markers or common prefixes
        m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", value.strip())
        if m:
            return find_comune(comuni, m.group(1), m.group(2))
        return None

    if len(matches) == 1:
        return matches[0]

    for match in matches:
        if not match.data_fine:
            return match
    return matches[0]


def detect_mime_type(path: Path) -> str:
    mime, _ = mimetypes.guess_type(str(path))
    if mime:
        return mime
    return "application/octet-stream"


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def build_jwt_assertion(service_account: dict) -> str:
    now = int(time.time())
    header = {"alg": "RS256", "typ": "JWT"}
    payload = {
        "iss": service_account["client_email"],
        "scope": TOKEN_SCOPE,
        "aud": service_account.get("token_uri") or DEFAULT_TOKEN_URI,
        "iat": now,
        "exp": now + 3600,
    }

    signing_input = f"{b64url(json.dumps(header, separators=(',', ':')).encode())}.{b64url(json.dumps(payload, separators=(',', ':')).encode())}".encode()

    private_key = serialization.load_pem_private_key(
        service_account["private_key"].encode("utf-8"),
        password=None,
    )
    signature = private_key.sign(signing_input, padding.PKCS1v15(), hashes.SHA256())
    return f"{signing_input.decode()}.{b64url(signature)}"


def fetch_access_token(config: dict) -> str:
    bearer = str(config.get("bearer_token") or "").strip()
    if bearer:
        return bearer

    credentials_path = str(config.get("credentials_path") or os.getenv("GOOGLE_APPLICATION_CREDENTIALS") or "").strip()
    if not credentials_path:
        raise OcrError("Credenziali Google Cloud non configurate. Imposta bearer_token oppure credentials_path / GOOGLE_APPLICATION_CREDENTIALS.")

    path = Path(credentials_path)
    if not path.is_file():
        raise OcrError(f"File credenziali non trovato: {credentials_path}")

    service_account = json.loads(path.read_text(encoding="utf-8"))
    assertion = build_jwt_assertion(service_account)
    token_uri = service_account.get("token_uri") or DEFAULT_TOKEN_URI
    response = requests.post(
        token_uri,
        data=urlencode({
            "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion": assertion,
        }),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        timeout=int(config.get("timeout_seconds") or 60),
    )
    if response.status_code >= 400:
        raise OcrError(f"Token Google non ottenuto: {response.status_code} {response.text[:300]}")

    payload = response.json()
    token = str(payload.get("access_token") or "").strip()
    if not token:
        raise OcrError("La risposta OAuth di Google non contiene access_token.")
    return token


def process_document(path: Path, config: dict, token: str) -> dict:
    endpoint = str(config.get("endpoint") or "").strip()
    if not endpoint:
        raise OcrError("Endpoint Document OCR non configurato.")
    if not path.is_file():
        raise OcrError(f"File documento non trovato: {path}")

    mime = detect_mime_type(path)
    content = base64.b64encode(path.read_bytes()).decode("ascii")
    body = {
        "rawDocument": {
            "mimeType": mime,
            "content": content,
        }
    }

    response = requests.post(
        endpoint,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json; charset=utf-8",
        },
        json=body,
        timeout=int(config.get("timeout_seconds") or 60),
    )

    if response.status_code >= 400:
        raise OcrError(f"Document OCR ha restituito {response.status_code}: {response.text[:500]}")

    payload = response.json()
    if not isinstance(payload, dict) or "document" not in payload:
        raise OcrError("La risposta del processore Document OCR non contiene l'oggetto document.")
    payload["_mime_type"] = mime
    payload["_filename"] = path.name
    return payload


def extract_document_text(document_response: dict) -> str:
    document = document_response.get("document") or {}
    text = document.get("text") or ""
    return str(text)


def extract_entities(document_response: dict) -> Dict[str, List[str]]:
    document = document_response.get("document") or {}
    entities = document.get("entities") or []
    text = str(document.get("text") or "")
    out: Dict[str, List[str]] = {}
    for entity in entities:
        if not isinstance(entity, dict):
            continue
        etype = normalize(str(entity.get("type") or entity.get("mentionText") or ""))
        mention = str(entity.get("mentionText") or "").strip()
        if not mention:
            text_anchor = ((entity.get("textAnchor") or {}).get("textSegments") or [])
            parts = []
            for seg in text_anchor:
                try:
                    start = int(seg.get("startIndex", 0))
                    end = int(seg.get("endIndex", 0))
                except Exception:
                    continue
                if end > start >= 0:
                    parts.append(text[start:end])
            mention = " ".join(p for p in parts if p).strip()
        if not etype or not mention:
            continue
        out.setdefault(etype, []).append(mention)
    return out


def prepare_lines(text: str) -> List[str]:
    lines = []
    for raw in re.split(r"\r?\n", text or ""):
        raw = re.sub(r"\s+", " ", raw).strip()
        if raw:
            lines.append(raw)
    return lines


def normalize_lines(lines: List[str]) -> List[str]:
    return [normalize(line) for line in lines]


GENERIC_LABEL_TOKENS = {
    "COGNOME", "SURNAME", "NOME", "NAME", "GIVEN", "GIVENNAME", "GIVENNAMES",
    "PRENOM", "PRENOMS", "NOM", "SESSO", "SEX", "DATA", "DATE", "BIRTH", "NASCITA", "PLACE",
    "LUOGO", "COMUNE", "CITY", "TOWN", "STATO", "COUNTRY", "RESIDENZA", "RESIDENCE", "ADDRESS",
    "ADRESSE", "NATIONALITY", "NAZIONALITA", "CITTADINANZA", "DOCUMENT", "DOCUMENTO",
    "NUMBER", "NUMERO", "NO", "RILASCIO", "RILASCIATO", "RILASCIATA", "AUTHORITY", "AUTORITA",
    "AUTORITE", "ISSUED", "ISSUING", "EXPIRY", "SCADENZA", "VALID", "VALIDITA", "DELIVERY",
    "IDENTITY", "CARD", "CARTE", "PATENTE", "GUIDA", "DRIVING", "LICENCE", "LICENSE", "PERMIS",
    "PLACEOFBIRTH", "DATEOFBIRTH", "PLACEOFRESIDENCE", "ISSUEDBY", "PLACEOFISSUE",
    "1", "2", "3", "4A", "4B", "4C", "4D", "5", "6", "7", "8", "9", "10", "11", "12"
}


def is_placeholder_value(value: str, label_norms: Optional[List[str]] = None) -> bool:
    normalized = normalize(value)
    if not normalized:
        return True

    blocked = set(GENERIC_LABEL_TOKENS)
    if label_norms:
        blocked.update(label_norms)

    if normalized in blocked or normalized.replace(" ", "") in blocked:
        return True

    tokens = [tok for tok in normalized.split() if tok]
    return bool(tokens) and all(tok in blocked for tok in tokens)


def collect_after_labels(lines_norm: List[str], labels: List[str], allow_same_line: bool = True, max_values: int = 4) -> List[str]:
    label_norms = [normalize(label) for label in labels if normalize(label)]
    if not label_norms:
        return []

    collected: List[str] = []
    seen = set()

    for idx, line in enumerate(lines_norm):
        for label in label_norms:
            if not line:
                continue

            matched = line == label
            inline_tail = ""

            if allow_same_line and not matched and label in line:
                tail = line.split(label, 1)[1].strip(" :-")
                if tail:
                    inline_tail = tail
                matched = True

            if not matched:
                continue

            if inline_tail and not is_placeholder_value(inline_tail, label_norms):
                if inline_tail not in seen:
                    collected.append(inline_tail)
                    seen.add(inline_tail)
                if len(collected) >= max_values:
                    return collected

            for nxt in lines_norm[idx + 1: idx + 1 + max_values]:
                candidate = nxt.strip()
                if not candidate or is_placeholder_value(candidate, label_norms) or candidate in seen:
                    continue
                collected.append(candidate)
                seen.add(candidate)
                if len(collected) >= max_values:
                    return collected

    return collected


def find_after_labels(lines_norm: List[str], labels: List[str], allow_same_line: bool = True) -> str:
    values = collect_after_labels(lines_norm, labels, allow_same_line=allow_same_line, max_values=4)
    return values[0] if values else ""
def first_match(patterns: List[str], text: str) -> str:
    for pattern in patterns:
        m = re.search(pattern, text, re.IGNORECASE)
        if m:
            for group in m.groups():
                if group:
                    return group
            return m.group(0)
    return ""


def parse_date(value: str) -> str:
    value = str(value or "").strip()
    if not value:
        return ""

    normalized = value.replace(".", "/").replace("-", "/")
    normalized = re.sub(r"\s+", "", normalized)

    for pattern in [r"(\d{2})/(\d{2})/(\d{4})", r"(\d{2})(\d{2})(\d{4})", r"(\d{4})/(\d{2})/(\d{2})", r"(\d{4})(\d{2})(\d{2})"]:
        m = re.search(pattern, normalized)
        if not m:
            continue
        if len(m.group(1)) == 4:
            year, month, day = m.group(1), m.group(2), m.group(3)
        else:
            day, month, year = m.group(1), m.group(2), m.group(3)
        try:
            return f"{int(year):04d}-{int(month):02d}-{int(day):02d}"
        except Exception:
            pass
    return ""


def parse_gender(value: str) -> str:
    normalized = normalize(value)
    if not normalized:
        return ""
    if normalized.startswith("M") or "MASCH" in normalized:
        return "M"
    if normalized.startswith("F") or "FEMM" in normalized:
        return "F"
    return ""


def clean_name_value(value: str) -> str:
    value = normalize(value)
    value = re.sub(r"\b(SURNAME|COGNOME|NAME|NOME|GIVEN NAMES|GIVENNAME|PRENOM|PRNOM)\b", " ", value)
    value = re.sub(r"\s+", " ", value).strip()
    return title_case(value)


def parse_mrz(lines: List[str]) -> Dict[str, str]:
    mrz_lines = [re.sub(r"\s+", "", line.upper()) for line in lines if "<" in line]
    mrz_lines = [line for line in mrz_lines if len(line) >= 25]
    if len(mrz_lines) >= 2:
        # TD3 passport style
        for i in range(len(mrz_lines) - 1):
            l1, l2 = mrz_lines[i], mrz_lines[i + 1]
            if len(l1) >= 40 and len(l2) >= 40 and l1.startswith("P<"):
                names = l1[5:].split("<<")
                surname = title_case(names[0].replace("<", " ")) if names else ""
                given = title_case(" ".join(part.replace("<", " ") for part in names[1:] if part)) if len(names) > 1 else ""
                doc_no = l2[0:9].replace("<", "").strip()
                nationality = l2[10:13].replace("<", "").strip()
                birth = l2[13:19]
                sex = l2[20:21]
                return {
                    "document_number": doc_no,
                    "citizenship_alpha3": nationality,
                    "birth_date_mrz": birth,
                    "gender": parse_gender(sex),
                    "last_name": surname,
                    "first_name": given,
                }
    if len(mrz_lines) >= 3:
        # TD1 identity card style
        for i in range(len(mrz_lines) - 2):
            l1, l2, l3 = mrz_lines[i], mrz_lines[i + 1], mrz_lines[i + 2]
            if len(l1) >= 30 and len(l2) >= 30 and len(l3) >= 30 and l1[:1] in {"I", "C", "A"}:
                names = l3.split("<<")
                surname = title_case(names[0].replace("<", " ")) if names else ""
                given = title_case(" ".join(part.replace("<", " ") for part in names[1:] if part)) if len(names) > 1 else ""
                doc_no = l1[5:14].replace("<", "").strip()
                nationality = l2[15:18].replace("<", "").strip()
                birth = l2[0:6]
                sex = l2[7:8]
                return {
                    "document_number": doc_no,
                    "citizenship_alpha3": nationality,
                    "birth_date_mrz": birth,
                    "gender": parse_gender(sex),
                    "last_name": surname,
                    "first_name": given,
                }
    return {}


def mrz_birth_to_iso(value: str) -> str:
    if not value or len(value) != 6 or not value.isdigit():
        return ""
    yy = int(value[0:2])
    mm = int(value[2:4])
    dd = int(value[4:6])
    current_two = int(time.strftime("%y"))
    year = 1900 + yy if yy > current_two else 2000 + yy
    return f"{year:04d}-{mm:02d}-{dd:02d}"


def alpha3_to_country(countries: List[Country], alpha3: str) -> Optional[Country]:
    mapping = {
        "ITA": "ITALIA",
        "ROU": "ROMANIA",
        "FRA": "FRANCIA",
        "DEU": "GERMANIA",
        "GBR": "REGNO UNITO",
        "USA": "STATI UNITI D'AMERICA",
        "NLD": "PAESI BASSI",
        "ESP": "SPAGNA",
        "POL": "POLONIA",
        "CZE": "CECHIA",
    }
    target = mapping.get((alpha3 or "").upper())
    return find_country(countries, target or "") if target else None


def detect_document_type(text_norm: str) -> str:
    if "PATENTE DI GUIDA" in text_norm or re.search(r"\b4D\b", text_norm):
        return "PATENTE DI GUIDA"
    if "PASSAPORTO" in text_norm or text_norm.startswith("P<"):
        return "PASSAPORTO ORDINARIO"
    if "CARTA DI IDENTITA" in text_norm or "IDENTITY CARD" in text_norm or "CARTA IDENTITA ELETTRONICA" in text_norm:
        return "CARTA DI IDENTITA'"
    return ""


def pick_document_number(text_norm: str, doc_type: str) -> str:
    labeled = find_after_labels(
        prepare_lines(text_norm),
        ["NUMERO", "DOCUMENT NO", "DOCUMENT NUMBER", "NO DOCUMENTO", "N. DOCUMENTO", "4D", "NUMERO PATENTE"],
    )
    candidate_text = labeled or text_norm
    patterns = []
    if doc_type == "CARTA DI IDENTITA'":
        patterns = [r"\b([A-Z]{2}\d{3,6}[A-Z]{1,2})\b"]
    elif doc_type == "PATENTE DI GUIDA":
        patterns = [r"\b([A-Z]{1,3}\d{5,8}[A-Z]{0,2})\b"]
    elif doc_type == "PASSAPORTO ORDINARIO":
        patterns = [r"\b([A-Z0-9]{7,9})\b"]
    else:
        patterns = [r"\b([A-Z0-9]{6,12})\b"]
    value = first_match(patterns, candidate_text)
    return value.replace(" ", "")


def infer_birth_place(lines_norm: List[str]) -> str:
    candidates = collect_after_labels(lines_norm, [
        "LUOGO DI NASCITA",
        "PLACE OF BIRTH",
        "COMUNE DI NASCITA",
        "BIRTH PLACE",
        "3 DATA E LUOGO DI NASCITA",
        "3",
    ], allow_same_line=True, max_values=6)
    for value in candidates:
        cleaned = value
        m = re.search(r"(\d{2}[./-]?\d{2}[./-]?\d{4}|\d{8})", cleaned)
        if m:
            cleaned = cleaned.replace(m.group(0), " ")
        cleaned = re.sub(r"(DATA E LUOGO DI NASCITA|DATE OF BIRTH|PLACE OF BIRTH|LUOGO DI NASCITA|BIRTH PLACE)", " ", cleaned)
        cleaned = re.sub(r"\s+", " ", cleaned).strip(" :-")
        if cleaned and not is_placeholder_value(cleaned):
            return cleaned
    return ""


def infer_residence_place(lines_norm: List[str]) -> str:
    candidates = collect_after_labels(lines_norm, [
        "COMUNE DI RESIDENZA",
        "RESIDENZA",
        "RESIDENCE",
        "ADDRESS",
        "INDIRIZZO",
        "ADDRESS OF RESIDENCE",
        "8 RESIDENZA",
        "8 ADDRESS",
        "8",
    ], allow_same_line=True, max_values=6)
    for value in candidates:
        if value and not is_placeholder_value(value):
            return value
    return ""


def infer_issue_place(lines_norm: List[str]) -> str:
    candidates = collect_after_labels(lines_norm, [
        "COMUNE DI RILASCIO",
        "LUOGO DI RILASCIO",
        "RILASCIATO DA",
        "RILASCIATA DA",
        "AUTORITA",
        "AUTHORITY",
        "ISSUED BY",
        "ISSUING AUTHORITY",
        "PLACE OF ISSUE",
        "4C",
    ], allow_same_line=True, max_values=6)
    for value in candidates:
        cleaned = re.sub(r"MINISTERO DELLE INFRASTRUTTURE E DEI TRASPORTI", " ", value)
        cleaned = re.sub(r"\s+", " ", cleaned).strip(" :-")
        if cleaned and not is_placeholder_value(cleaned):
            return cleaned
    return ""
def resolve_birth_place(raw_place: str, countries: List[Country], comuni: List[Comune]) -> Dict[str, str]:
    raw_place = str(raw_place or "").strip()
    if not raw_place:
        return {}

    m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", raw_place.strip(), re.IGNORECASE)
    province = m.group(2).upper() if m else ""
    comune_value = m.group(1).strip() if m else raw_place
    comune = find_comune(comuni, comune_value, province)
    if comune:
        return {
            "birth_state_label": "100000100",
            "birth_province": comune.province,
            "birth_place_label": comune.code,
            "birth_city_code": comune.code,
        }

    country = find_country(countries, raw_place)
    if country:
        return {
            "birth_state_label": country.code,
            "birth_province": "",
            "birth_place_label": "",
            "birth_city_code": "",
        }

    return {}


def resolve_residence_place(raw_place: str, countries: List[Country], comuni: List[Comune], explicit_state: Optional[Country]) -> Dict[str, str]:
    raw_place = str(raw_place or "").strip()
    if not raw_place:
        return {}

    m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", raw_place.strip(), re.IGNORECASE)
    province = m.group(2).upper() if m else ""
    comune_value = m.group(1).strip() if m else raw_place
    comune = find_comune(comuni, comune_value, province)
    if comune:
        return {
            "residence_state_label": "100000100",
            "residence_province": comune.province,
            "residence_place_label": comune.code,
            "residence_place_code": comune.code,
        }

    if explicit_state and explicit_state.code != "100000100":
        return {
            "residence_state_label": explicit_state.code,
            "residence_province": "",
            "residence_place_label": raw_place,
            "residence_place_code": raw_place,
        }

    country = find_country(countries, raw_place)
    if country and country.code != "100000100":
        return {
            "residence_state_label": country.code,
            "residence_province": "",
            "residence_place_label": raw_place,
            "residence_place_code": raw_place,
        }

    return {}


def resolve_issue_place(raw_place: str, countries: List[Country], comuni: List[Comune]) -> str:
    raw_place = str(raw_place or "").strip()
    if not raw_place:
        return ""
    m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", raw_place.strip(), re.IGNORECASE)
    province = m.group(2).upper() if m else ""
    comune_value = m.group(1).strip() if m else raw_place
    comune = find_comune(comuni, comune_value, province)
    if comune:
        return comune.label
    country = find_country(countries, raw_place)
    if country:
        return country.description
    return ""


def map_document(payload: dict, data_dir: Path) -> dict:
    countries = load_countries(data_dir)
    comuni = load_comuni(data_dir)

    front = payload.get("front") or {}
    back = payload.get("back") or {}
    docs = [front, back]
    texts = [extract_document_text(doc) for doc in docs if doc]
    combined_text = "\n".join(texts)
    lines = prepare_lines(combined_text)
    lines_norm = normalize_lines(lines)
    text_norm = "\n".join(lines_norm)
    entities = {}
    for doc in docs:
        if doc:
            for key, values in extract_entities(doc).items():
                entities.setdefault(key, []).extend(values)

    warnings: List[str] = []
    extracted: Dict[str, str] = {}
    display: Dict[str, str] = {}

    mrz = parse_mrz(lines)

    doc_type = detect_document_type(text_norm)
    if not doc_type:
        doc_type = "CARTA DI IDENTITA'" if "REPUBBLICA ITALIANA" in text_norm or "IDENTITY CARD" in text_norm else ""

    last_name = clean_name_value(find_after_labels(lines_norm, ["COGNOME", "SURNAME", "FAMILY NAME", "NOM", "1 COGNOME", "1 SURNAME"], True) or mrz.get("last_name", ""))
    first_name = clean_name_value(find_after_labels(lines_norm, ["NOME", "GIVEN NAMES", "GIVEN NAME", "NAME", "PRENOM", "2 NOME", "2 NAME"], True) or mrz.get("first_name", ""))

    gender = parse_gender(find_after_labels(lines_norm, ["SESSO", "SEX"], True) or mrz.get("gender", ""))

    birth_date_raw = find_after_labels(lines_norm, ["DATA DI NASCITA", "DATE OF BIRTH", "BIRTH DATE", "3 DATA E LUOGO DI NASCITA", "3"], True)
    birth_date = parse_date(birth_date_raw) or mrz_birth_to_iso(mrz.get("birth_date_mrz", ""))

    citizenship_raw = find_after_labels(lines_norm, ["CITTADINANZA", "NATIONALITY", "NAZIONALITA", "NATIONALITE", "COUNTRY"], True)
    citizenship = find_country(countries, citizenship_raw) if citizenship_raw else None
    if not citizenship and mrz.get("citizenship_alpha3"):
        citizenship = alpha3_to_country(countries, mrz.get("citizenship_alpha3", ""))
    if not citizenship and doc_type in {"CARTA DI IDENTITA'", "PATENTE DI GUIDA"} and "REPUBBLICA ITALIANA" in text_norm:
        citizenship = find_country(countries, "ITALIA")
        warnings.append("Cittadinanza dedotta dal tipo di documento italiano: verifica prima del salvataggio.")

    birth_place_raw = infer_birth_place(lines_norm)

    residence_state_raw = find_after_labels(lines_norm, ["STATO DI RESIDENZA", "COUNTRY OF RESIDENCE"], True)
    residence_state = find_country(countries, residence_state_raw) if residence_state_raw else None
    residence_place_raw = infer_residence_place(lines_norm)

    issue_place_raw = infer_issue_place(lines_norm)

    document_number = mrz.get("document_number", "") or pick_document_number(text_norm, doc_type)
    if not birth_date:
        birth_date = parse_date(first_match([
            r"\b(\d{2}[./-]\d{2}[./-]\d{4})\b",
            r"\b(\d{8})\b",
        ], text_norm))

    if not birth_place_raw:
        birth_place_raw = first_match([
            r"(?:PLACE OF BIRTH|LUOGO DI NASCITA|COMUNE DI NASCITA)\s+([A-Z' ]+(?:\([A-Z]{2}\))?)",
            r"\bNAT[OA] A\s+([A-Z' ]+(?:\([A-Z]{2}\))?)",
        ], text_norm)

    if not residence_place_raw:
        residence_place_raw = first_match([
            r"(?:RESIDENZA|RESIDENCE|ADDRESS|INDIRIZZO)\s+([A-Z0-9' .,/-]+)",
        ], text_norm)

    if not issue_place_raw:
        issue_place_raw = first_match([
            r"(?:RILASCIAT[OA] DA|AUTORITA|AUTHORITY|ISSUED BY|4C)\s+([A-Z0-9' .-]+)",
        ], text_norm)

    birth_place_map = resolve_birth_place(birth_place_raw, countries, comuni)
    residence_map = resolve_residence_place(residence_place_raw, countries, comuni, residence_state)
    issue_place_code = resolve_issue_place(issue_place_raw, countries, comuni)

    if first_name:
        extracted["first_name"] = first_name
        display["Nome"] = first_name
    if last_name:
        extracted["last_name"] = last_name
        display["Cognome"] = last_name
    if gender:
        extracted["gender"] = gender
        display["Sesso"] = "Maschio" if gender == "M" else "Femmina"
    if birth_date:
        extracted["birth_date"] = birth_date
        display["Data nascita"] = birth_date
    if citizenship:
        extracted["citizenship_label"] = citizenship.code
        display["Cittadinanza"] = citizenship.description
    if doc_type:
        extracted["document_type_label"] = doc_type
        display["Tipo documento"] = doc_type
    if document_number:
        extracted["document_number"] = document_number
        display["Numero documento"] = document_number

    extracted.update({k: v for k, v in birth_place_map.items() if v})
    if birth_place_map.get("birth_province"):
        display["Provincia nascita"] = birth_place_map["birth_province"]
    if birth_place_map.get("birth_city_code"):
        comune = find_comune(comuni, birth_place_map["birth_city_code"])
        if comune:
            display["Comune nascita"] = comune.label
    elif birth_place_raw:
        display["Luogo nascita"] = birth_place_raw

    extracted.update({k: v for k, v in residence_map.items() if v})
    if residence_map.get("residence_province"):
        display["Provincia residenza"] = residence_map["residence_province"]
    if residence_map.get("residence_place_code") and residence_map.get("residence_state_label") == "100000100":
        comune = find_comune(comuni, residence_map["residence_place_code"])
        if comune:
            display["Comune residenza"] = comune.label
    elif residence_place_raw:
        display["Residenza"] = residence_place_raw

    if issue_place_code:
        extracted["document_issue_place"] = issue_place_code
        display["Luogo rilascio"] = issue_place_code
    elif issue_place_raw:
        warnings.append("Luogo di rilascio documento trovato ma non riconosciuto automaticamente: verifica il campo manualmente.")

    if not residence_map and residence_place_raw:
        warnings.append("Residenza rilevata ma non riconosciuta automaticamente: completa il comune/località manualmente.")
    if not birth_place_map and birth_place_raw:
        warnings.append("Luogo di nascita rilevato ma non riconosciuto automaticamente: completa provincia/comune o stato manualmente.")
    if not first_name or not last_name:
        warnings.append("Nome e/o cognome non rilevati con sicurezza: verifica i campi dati persona.")
    if not birth_date:
        warnings.append("Data di nascita non rilevata con sicurezza.")
    if not doc_type:
        warnings.append("Tipo documento non riconosciuto con certezza: selezionalo manualmente.")
    if not document_number:
        warnings.append("Numero documento non rilevato con sicurezza.")

    return {
        "form_payload": extracted,
        "display_payload": display,
        "warnings": warnings,
        "raw": {
            "birth_place": birth_place_raw,
            "residence_place": residence_place_raw,
            "issue_place": issue_place_raw,
            "citizenship": citizenship_raw,
        },
        "documents": [
            {
                "side": "front",
                "filename": front.get("_filename") if front else None,
                "mime_type": front.get("_mime_type") if front else None,
                "text_excerpt": extract_document_text(front)[:400] if front else "",
            },
            {
                "side": "back",
                "filename": back.get("_filename") if back else None,
                "mime_type": back.get("_mime_type") if back else None,
                "text_excerpt": extract_document_text(back)[:400] if back else "",
            } if back else None,
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--stdin-json", action="store_true", help="Legge il payload da stdin")
    args = parser.parse_args()

    try:
        payload = json.loads(sys.stdin.read() if args.stdin_json or not sys.stdin.isatty() else "{}")
        if not isinstance(payload, dict):
            raise OcrError("Payload stdin non valido.")

        config = payload.get("config") or {}
        front_path = Path(str(payload.get("front_path") or "")).expanduser()
        back_raw = str(payload.get("back_path") or "").strip()
        back_path = Path(back_raw).expanduser() if back_raw else None
        data_dir = Path(str(payload.get("data_dir") or "")).expanduser()
        if not data_dir.is_dir():
            raise OcrError(f"Directory dati lookup non trovata: {data_dir}")

        token = fetch_access_token(config)
        front = process_document(front_path, config, token)
        back = process_document(back_path, config, token) if back_path else None

        mapped = map_document({"front": front, "back": back}, data_dir)
        result = {
            "ok": True,
            "processor": {
                "endpoint": str(config.get("endpoint") or ""),
            },
            "result": mapped,
            "raw_document_ai": {
                "front": front,
                "back": back,
            },
        }
        sys.stdout.write(json.dumps(result, ensure_ascii=False))
        return 0
    except OcrError as exc:
        sys.stderr.write(str(exc))
        return 2
    except Exception as exc:
        sys.stderr.write(f"Errore OCR inatteso: {exc}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())