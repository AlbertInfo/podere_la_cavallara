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
from typing import Dict, List, Optional
from urllib.parse import urlencode

import requests
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding


TOKEN_SCOPE = "https://www.googleapis.com/auth/cloud-platform"
DEFAULT_TOKEN_URI = "https://oauth2.googleapis.com/token"
ITALY_CODE = "100000100"

MONTHS = {
    "JAN": 1, "JANUARY": 1, "GEN": 1, "GENNAIO": 1, "JANVIER": 1,
    "FEB": 2, "FEBRUARY": 2, "FEBBRAIO": 2, "FEV": 2, "FEVR": 2, "FEVRIER": 2,
    "MAR": 3, "MARCH": 3, "MARZO": 3, "MARS": 3,
    "APR": 4, "APRIL": 4, "APRILE": 4, "AVR": 4, "AVRIL": 4,
    "MAY": 5, "MAG": 5, "MAGGIO": 5, "MAI": 5,
    "JUN": 6, "JUNE": 6, "GIU": 6, "GIUGNO": 6, "JUIN": 6,
    "JUL": 7, "JULY": 7, "LUG": 7, "LUGLIO": 7, "JUIL": 7, "JUILLET": 7,
    "AUG": 8, "AUGUST": 8, "AGO": 8, "AGOSTO": 8, "AOUT": 8,
    "SEP": 9, "SEPT": 9, "SEPTEMBER": 9, "SET": 9, "SETTEMBRE": 9,
    "OCT": 10, "OCTOBER": 10, "OTT": 10, "OTTOBRE": 10, "OCTOBRE": 10,
    "NOV": 11, "NOVEMBER": 11, "NOVEMBRE": 11,
    "DEC": 12, "DECEMBER": 12, "DIC": 12, "DICEMBRE": 12, "DECEMBRE": 12,
}

LABEL_STOPWORDS = {
    "SURNAME", "COGNOME", "NOME", "NAME", "GIVEN", "GIVEN NAMES", "FIRST AND MIDDLE NAMES",
    "PRENOMS", "PRENOMS", "PRENOM", "VORNAMEN", "ETTERNAVN", "FORNAVN", "FIRST", "MIDDLE",
    "DATE OF BIRTH", "DATA DI NASCITA", "LUOGO DI NASCITA", "PLACE OF BIRTH", "GEBURTSORT", "GEBURTSTAG",
    "SESSO", "SEX", "SEXE", "NATIONALITY", "NAZIONALITA", "NATIONALITE", "CITTADINANZA",
    "RESIDENCE", "ADDRESS", "INDIRIZZO DI RESIDENZA", "COMUNE DI RESIDENZA", "COMUNE RESIDENZA",
    "EMISSIONE", "ISSUING", "AUTHORITY", "ISSUING AUTHORITY", "AUTORITA", "HEIGHT", "STATURA",
    "EXPIRY", "DATE OF EXPIRY", "DATE D EXPIRY", "EXPIRY DATE", "CAN",
}

ADDRESS_WORDS = {
    "VIA", "VIALE", "PIAZZA", "CORSO", "LARGO", "STRADA", "LOC", "LOCALITA", "TRAVERSA",
    "RUE", "AVENUE", "BOULEVARD", "STREET", "ROAD", "RD", "DRIVE", "RESIDENTIE", "RESIDENCE",
    "N", "NO", "NR", "HOUSE", "APT", "UNIT", "CALLE",
}

DOC_PROFILE_TO_COUNTRY = {
    "it_cie": "ITALIA",
    "fr_id": "FRANCIA",
    "de_id": "GERMANIA",
    "no_passport": "NORVEGIA",
}


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
    value = re.sub(r"[’'`.,;:/\\\-_()\[\]{}\"°º]", " ", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def title_case(value: str) -> str:
    value = re.sub(r"\s+", " ", str(value or "").strip())
    if not value:
        return ""
    titled = value.lower().title()
    titled = re.sub(r"\bD([A-Z])\b", lambda m: "D'" + m.group(1), titled)
    titled = titled.replace("'S", "'s")
    titled = re.sub(r"\bMc([A-Z])", lambda m: "Mc" + m.group(1), titled)
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
        "ITA": "ITALIA",
        "ROMANIA": "ROMANIA",
        "ROU": "ROMANIA",
        "FRA": "FRANCIA",
        "FRANCE": "FRANCIA",
        "FRANCAISE": "FRANCIA",
        "FRENCH": "FRANCIA",
        "DEU": "GERMANIA",
        "GERMANY": "GERMANIA",
        "DEUTSCHLAND": "GERMANIA",
        "DEUTSCH": "GERMANIA",
        "NOR": "NORVEGIA",
        "NORWAY": "NORVEGIA",
        "NORGE": "NORVEGIA",
        "NOREG": "NORVEGIA",
        "NORSK": "NORVEGIA",
        "USA": "STATI UNITI D'AMERICA",
        "U S A": "STATI UNITI D'AMERICA",
        "UNITED STATES": "STATI UNITI D'AMERICA",
        "UNITED STATES OF AMERICA": "STATI UNITI D'AMERICA",
        "GBR": "REGNO UNITO",
        "UK": "REGNO UNITO",
        "GREAT BRITAIN": "REGNO UNITO",
        "UNITED KINGDOM": "REGNO UNITO",
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


def extract_entity_value(entity: dict, text: str) -> str:
    def _append_unique(buf: List[str], value: str) -> None:
        value = re.sub(r"\s+", " ", str(value or "")).strip()
        if value and value not in buf:
            buf.append(value)

    values: List[str] = []
    norm = entity.get("normalizedValue") or {}
    if isinstance(norm, dict):
        for key in ("text", "stringValue"):
            _append_unique(values, norm.get(key) or "")
        date_val = norm.get("dateValue") or {}
        if isinstance(date_val, dict):
            year = int(date_val.get("year") or 0)
            month = int(date_val.get("month") or 0)
            day = int(date_val.get("day") or 0)
            if year and month and day:
                _append_unique(values, f"{day:02d}.{month:02d}.{year:04d}")
                _append_unique(values, f"{year:04d}-{month:02d}-{day:02d}")
        addr = norm.get("addressValue") or {}
        if isinstance(addr, dict):
            lines = addr.get("addressLines")
            if isinstance(lines, list):
                _append_unique(values, ", ".join(str(x).strip() for x in lines if str(x).strip()))
            parts = []
            for key in ("addressLines", "postalCode", "locality", "administrativeArea", "regionCode"):
                raw = addr.get(key)
                if isinstance(raw, list):
                    parts.extend(str(x).strip() for x in raw if str(x).strip())
                elif raw:
                    parts.append(str(raw).strip())
            _append_unique(values, ", ".join(x for x in parts if x))
    mention = str(entity.get("mentionText") or "").strip()
    if mention:
        _append_unique(values, mention)
    if not values:
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
        _append_unique(values, " ".join(p for p in parts if p).strip())
    return values[0] if values else ""


def extract_entities(document_response: dict) -> Dict[str, List[str]]:
    document = document_response.get("document") or {}
    entities = document.get("entities") or []
    text = str(document.get("text") or "")
    out: Dict[str, List[str]] = {}
    for entity in entities:
        if not isinstance(entity, dict):
            continue
        etype = normalize(str(entity.get("type") or entity.get("mentionText") or ""))
        mention = extract_entity_value(entity, text)
        if not etype or not mention:
            continue
        out.setdefault(etype, [])
        if mention not in out[etype]:
            out[etype].append(mention)
    return out


def entity_key_variants(value: str) -> List[str]:
    norm = normalize(value)
    compact = re.sub(r"\s+", "", norm)
    return [x for x in [norm, compact] if x]


def entity_key_matches(key: str, aliases: List[str]) -> bool:
    key_norm = normalize(key)
    key_compact = re.sub(r"\s+", "", key_norm)
    for alias in aliases:
        for candidate in entity_key_variants(alias):
            if not candidate:
                continue
            if key_norm == candidate or key_compact == candidate:
                return True
            if candidate in key_norm or candidate in key_compact:
                return True
    return False


def get_entity_values(entities: Dict[str, List[str]], aliases: List[str]) -> List[str]:
    out: List[str] = []
    for key, values in entities.items():
        if entity_key_matches(key, aliases):
            for value in values:
                value = re.sub(r"\s+", " ", str(value or "")).strip()
                if value and value not in out:
                    out.append(value)
    return out


def get_entity_first(entities: Dict[str, List[str]], aliases: List[str]) -> str:
    values = get_entity_values(entities, aliases)
    return values[0] if values else ""


def normalize_doc_type_label(value: str) -> str:
    normalized = normalize(value)
    if not normalized:
        return ""
    if "PATENTE" in normalized or "DRIVING LICENCE" in normalized or "DRIVER" in normalized:
        return "PATENTE DI GUIDA"
    if "PASSAPORT" in normalized or "PASSPORT" in normalized:
        return "PASSAPORTO ORDINARIO"
    if "CARTA DI IDENTITA" in normalized or "IDENTITY CARD" in normalized or "PERSONALAUSWEIS" in normalized or "IDENTIFICATION CARD" in normalized:
        return "CARTA DI IDENTITA'"
    return title_case(value)


def collect_custom_processor_fields(front_entities: Dict[str, List[str]], back_entities: Dict[str, List[str]]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    birth_combo = get_entity_first(front_entities, [
        "LUOGO E DATA DI NASCITA", "LUOGOEDATADINASCITA", "PLACE AND DATE OF BIRTH", "LUOGOEDATANASCITA"
    ])
    split = split_place_and_date(birth_combo)
    residence_raw = get_entity_first(back_entities, [
        "INDIRIZZO DI RESIDENZA", "INDIRIZZODIRESIDENZA", "RESIDENCE ADDRESS", "ADDRESS", "RESIDENZA"
    ])
    issue_place = get_entity_first(front_entities, [
        "COMUNE DI MUNICIPALITY", "COMUNEDIMUNICIPALITY", "MUNICIPALITY", "COMUNE DI"
    ])
    doc_number = get_entity_first(front_entities, [
        "NUMERO DOCUMENTO", "NUMERO_DOCUMENTO", "NUMERODOCUMENTO", "DOCUMENT NUMBER", "DOCUMENT NO", "NUMERO"
    ])
    doc_number = re.sub(r"[^A-Z0-9]", "", normalize(doc_number)) if doc_number else ""
    out.update({
        "doc_type": normalize_doc_type_label(get_entity_first(front_entities, [
            "TIPO DOCUMENTO", "TIPO_DOCUMENTO", "TIPODOCUMENTO", "DOCUMENT TYPE", "TYPE"
        ])),
        "last_name": clean_name_value(get_entity_first(front_entities, ["COGNOME", "SURNAME"])),
        "first_name": clean_name_value(get_entity_first(front_entities, ["NOME", "NAME", "GIVEN NAMES", "PRENOMS"])),
        "gender": parse_gender(get_entity_first(front_entities, ["SESSO", "SEX", "SEXE"])),
        "birth_date": parse_date(birth_combo),
        "birth_place_raw": maybe_attach_province(split.get("place", "")),
        "citizenship_raw": get_entity_first(front_entities, ["CITTADINANZA", "NATIONALITY", "CITIZENSHIP"]),
        "issue_place_raw": sanitize_place_value(issue_place),
        "issue_date": parse_date(get_entity_first(front_entities, ["EMISSIONE", "ISSUING", "DATE OF ISSUE"])),
        "expiry_date": parse_date(get_entity_first(front_entities, ["SCADENZA", "EXPIRY", "DATE OF EXPIRY", "EXPIRY DATE"])),
        "document_number": doc_number,
        "residence_raw": residence_raw,
        "fiscal_code": get_entity_first(back_entities, ["CODICE FISCALE", "CODICEFISCALE", "FISCAL CODE"]),
    })
    return {k: v for k, v in out.items() if v}


def prepare_lines(text: str) -> List[str]:
    lines = []
    for raw in re.split(r"\r?\n", text or ""):
        raw = re.sub(r"\s+", " ", raw).strip()
        if raw:
            lines.append(raw)
    return lines


def normalize_lines(lines: List[str]) -> List[str]:
    return [normalize(line) for line in lines]


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

    normalized = normalize(value)
    normalized = normalized.replace(".", "/").replace("-", "/")
    normalized = re.sub(r"\s+", " ", normalized).strip()

    month_pat = "|".join(sorted(MONTHS.keys(), key=len, reverse=True))
    textual = re.search(rf"\b(\d{{1,2}})\s+({month_pat})\s+(\d{{2,4}})\b", normalized)
    if textual:
        dd = int(textual.group(1))
        mm = MONTHS.get(textual.group(2), 0)
        yy = textual.group(3)
        if mm:
            year = int(yy)
            if len(yy) == 2:
                current_two = int(time.strftime("%y"))
                year = 1900 + year if year > current_two else 2000 + year
            return f"{year:04d}-{mm:02d}-{dd:02d}"

    compact = normalized.replace(" ", "")
    for pattern in [r"(\d{2})/(\d{2})/(\d{4})", r"(\d{2})(\d{2})(\d{4})", r"(\d{4})/(\d{2})/(\d{2})", r"(\d{4})(\d{2})(\d{2})"]:
        m = re.search(pattern, compact)
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
    if normalized in {"M", "MALE", "MASCHIO", "MANNLICH"} or normalized.startswith("M ") or " MASCH" in normalized:
        return "M"
    if normalized in {"F", "FEMALE", "FEMMINA", "WEIBLICH"} or normalized.startswith("F ") or " FEMM" in normalized:
        return "F"
    return ""


def looks_like_document_code_fragment(value: str) -> bool:
    v = normalize(value)
    if not v:
        return False
    compact = v.replace(' ', '')
    if re.fullmatch(r"[A-Z]{1,3}\d{4,8}[A-Z0-9]{0,3}", compact):
        return True
    tokens = [t for t in compact.split() if t]
    if len(tokens) >= 2 and all(len(t) <= 2 for t in tokens):
        return True
    return False


def clean_name_value(value: str) -> str:
    value = normalize(value)
    if not value:
        return ""
    value = re.sub(r"^\s*\[[A-Z]\]\s*", "", value)
    value = re.sub(r"^\s*\d+[A-Z]?\s+", "", value)
    for stop in sorted(LABEL_STOPWORDS, key=len, reverse=True):
        value = re.sub(rf"{re.escape(stop)}", " ", value)
    value = re.sub(r"SPECIMEN", " ", value)
    value = re.sub(r"[^A-ZÀ-ÿ'\- ]", " ", value)
    value = re.sub(r"\s+", " ", value).strip()
    if not value:
        return ""
    tokens = [t for t in value.split() if t]
    if len(tokens) >= 2 and all(len(t) <= 2 for t in tokens):
        return ""
    if looks_like_document_code_fragment(value):
        return ""
    return title_case(value)

def parse_mrz(lines: List[str]) -> Dict[str, str]:
    mrz_lines = [re.sub(r"\s+", "", line.upper()) for line in lines if "<" in line]
    mrz_lines = [line for line in mrz_lines if len(line) >= 25]
    if len(mrz_lines) >= 2:
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
        "NOR": "NORVEGIA",
    }
    target = mapping.get((alpha3 or "").upper())
    return find_country(countries, target or "") if target else None


def detect_document_type(text_norm: str) -> str:
    if "PATENTE DI GUIDA" in text_norm or re.search(r"\b4D\b", text_norm):
        return "PATENTE DI GUIDA"
    if "PASSAPORTO" in text_norm or "PASSPORT" in text_norm or text_norm.startswith("P<"):
        return "PASSAPORTO ORDINARIO"
    if "CARTA DI IDENTITA" in text_norm or "IDENTITY CARD" in text_norm or "PERSONALAUSWEIS" in text_norm or "IDENTIFICATION CARD" in text_norm:
        return "CARTA DI IDENTITA'"
    return ""


def detect_document_profile(text_norm: str) -> str:
    if "REPUBBLICA ITALIANA" in text_norm and ("CARTA DI IDENTITA" in text_norm or "IDENTITY CARD" in text_norm or "MINISTERO DELL INTERNO" in text_norm):
        return "it_cie"
    if "REPUBLIQUE FRANCAISE" in text_norm and ("CARTE NATIONALE D IDENTITE" in text_norm or "IDENTITY CARD" in text_norm):
        return "fr_id"
    if "PERSONALAUSWEIS" in text_norm or "BUNDESREPUBLIK DEUTSCHLAND" in text_norm:
        return "de_id"
    if "PASSPORT" in text_norm and ("P NOR" in text_norm or "NORGE" in text_norm or "NORWAY" in text_norm):
        return "no_passport"
    if "IDENTIFICATION CARD" in text_norm:
        return "generic_id"
    return "generic"


def strip_leading_markers(value: str) -> str:
    value = re.sub(r"^\s*\[[A-Z]\]\s*", "", value)
    value = re.sub(r"^\s*\d+[A-Z]?\s+", "", value)
    value = re.sub(r"^\s*[-:–]+\s*", "", value)
    return value.strip()


def remove_label_tokens(value: str, labels: List[str], stop_labels: Optional[List[str]] = None) -> str:
    candidate = normalize(value)
    for label in sorted({normalize(x) for x in (labels + (stop_labels or [])) if normalize(x)}, key=len, reverse=True):
        candidate = re.sub(rf"\b{re.escape(label)}\b", " ", candidate)
    candidate = strip_leading_markers(candidate)
    candidate = re.sub(r"\s+", " ", candidate).strip(" :-/")
    return candidate.strip()


def looks_like_field_header(value: str) -> bool:
    value = normalize(value)
    if not value:
        return False
    if '<' in value or value.count('<') >= 2:
        return True
    if '/' in value and any(token in value for token in LABEL_STOPWORDS):
        return True
    token_hits = sum(1 for token in LABEL_STOPWORDS if f" {token} " in f" {value} ")
    if token_hits >= 2:
        return True
    if token_hits >= 1 and len(value.split()) <= 6:
        return True
    return False


def is_noise_candidate(value: str, stop_labels: Optional[List[str]] = None, allow_date: bool = False) -> bool:
    value = normalize(value)
    if not value:
        return True
    if value in LABEL_STOPWORDS or looks_like_field_header(value):
        return True
    if stop_labels:
        stop_norms = {normalize(x) for x in stop_labels if normalize(x)}
        if value in stop_norms:
            return True
        if any(f" {stop} " in f" {value} " for stop in stop_norms if len(stop) > 2):
            return True
    if re.fullmatch(r"\d{1,3}\s*CM", value):
        return True
    if not allow_date and parse_date(value):
        return True
    if re.fullmatch(r"[A-Z]{1,3}\d{0,2}", value):
        return True
    return False


def extract_value_by_labels(lines_norm: List[str], labels: List[str], stop_labels: Optional[List[str]] = None, lookahead: int = 1, allow_date: bool = False, join_lines: bool = False) -> str:
    label_norms = [normalize(label) for label in labels if normalize(label)]
    stop_norms = [normalize(label) for label in (stop_labels or []) if normalize(label)]
    all_stops = sorted(set(label_norms + stop_norms), key=len, reverse=True)
    for index, line in enumerate(lines_norm):
        if not line:
            continue
        matched = False
        for label in label_norms:
            if line == label or line.startswith(label + " ") or f" {label} " in f" {line} ":
                matched = True
                break
        if not matched:
            continue

        inline = remove_label_tokens(line, labels, stop_labels)
        if inline and not is_noise_candidate(inline, all_stops, allow_date=allow_date):
            return inline

        collected: List[str] = []
        for next_line in lines_norm[index + 1:index + 1 + lookahead]:
            if not next_line:
                continue
            if any(next_line == stop or next_line.startswith(stop + " ") or f" {stop} " in f" {next_line} " for stop in all_stops):
                break
            next_clean = strip_leading_markers(next_line)
            if looks_like_field_header(next_clean):
                break
            if '<' in next_clean:
                break
            if is_noise_candidate(next_clean, all_stops, allow_date=allow_date):
                continue
            collected.append(next_clean)
            if not join_lines:
                return next_clean
        if collected:
            return " ".join(collected)
    return ""

def extract_inline_or_following_block(lines_norm: List[str], labels: List[str], stop_labels: Optional[List[str]] = None, lookahead: int = 3, allow_date: bool = False) -> str:
    value = extract_value_by_labels(lines_norm, labels, stop_labels=stop_labels, lookahead=lookahead, allow_date=allow_date, join_lines=True)
    value = re.sub(r"\s+", " ", value).strip()
    return value


def looks_like_street_address(value: str) -> bool:
    v = normalize(value)
    if not v:
        return False
    if any(token in v.split() for token in ADDRESS_WORDS):
        return True
    return bool(re.search(r"\d{1,4}[A-Z]?", v))


def extract_it_cie_issue_place(front_norm: List[str]) -> str:
    stop = [
        "COGNOME SURNAME", "NOME NAME", "LUOGO E DATA DI NASCITA", "SESSO SEX",
        "CITTADINANZA NATIONALITY", "STATURA HEIGHT", "DATE OF EXPIRY"
    ]
    value = extract_inline_or_following_block(front_norm, ["COMUNE DI MUNICIPALITY", "COMUNE DI", "MUNICIPALITY"], stop_labels=stop, lookahead=2)
    value = sanitize_place_value(value)
    return maybe_attach_province(value)


def extract_it_cie_birth_combo(front_norm: List[str]) -> str:
    stop = [
        "SESSO SEX", "STATURA HEIGHT", "CITTADINANZA NATIONALITY", "EMISSIONE ISSUING", "DATE OF EXPIRY"
    ]
    value = extract_inline_or_following_block(front_norm, [
        "LUOGO E DATA DI NASCITA PLACE AND DATE OF BIRTH", "PLACE AND DATE OF BIRTH", "LUOGO E DATA DI NASCITA", "PLACE OF BIRTH", "LUOGO DI NASCITA"
    ], stop_labels=stop, lookahead=2, allow_date=True)
    if value:
        return value
    joined = " | ".join(front_norm)
    m = re.search(r"(?:LUOGO E DATA DI NASCITA|PLACE AND DATE OF BIRTH|LUOGO DI NASCITA|PLACE OF BIRTH)\s+(.+?)\s+(\d{1,2}[./]\d{1,2}[./]\d{4}|\d{1,2}\s+[A-Z]{3,9}\s+\d{2,4})", joined)
    if m:
        return f"{m.group(1)} {m.group(2)}"
    return ""


def extract_it_cie_residence(back_norm: List[str]) -> str:
    stop = [
        "ESTREMI ATTO DI NASCITA", "CODICE FISCALE", "FISCAL CODE", "EMISSIONE ISSUING",
        "DATE OF EXPIRY", "LUOGO E DATA DI NASCITA", "COMUNE DI MUNICIPALITY"
    ]
    value = extract_inline_or_following_block(back_norm, [
        "INDIRIZZO DI RESIDENZA RESIDENCE", "INDIRIZZO DI RESIDENZA", "RESIDENCE ADDRESS", "RESIDENCE"
    ], stop_labels=stop, lookahead=2)
    value = value.strip()
    if normalize(value) in {"RESIDENCE", "ADDRESS", "EMISSIONE", "ISSUING"}:
        return ""
    if re.search(r"(EMISSIONE|ISSUING|ESTREMI|FISCAL CODE|CODICE FISCALE)", normalize(value)):
        return ""
    return value


def split_address_and_locality(value: str) -> Dict[str, str]:
    raw = re.sub(r"\s+", " ", str(value or "")).strip(" ,")
    if not raw:
        return {"address": "", "locality": ""}
    parts = [part.strip() for part in re.split(r",| - ", raw) if part.strip()]
    locality = ""
    address = raw
    if parts:
        for part in reversed(parts):
            if re.search(r"\([A-Z]{2}\)", part) or (not looks_like_street_address(part) and len(part.split()) <= 5 and not re.search(r"\d", part)):
                locality = part
                address = raw.replace(part, "").strip(" ,-")
                break
    return {"address": address, "locality": locality}


def split_place_and_date(value: str) -> Dict[str, str]:
    raw = normalize(value)
    if not raw:
        return {"place": "", "date": ""}
    date = parse_date(raw)
    place = raw
    if date:
        for patt in [r"\b\d{2}[./-]\d{2}[./-]\d{4}\b", r"\b\d{1,2}\s+\d{1,2}\s+\d{4}\b", r"\b\d{1,2}\s+[A-Z]{3,9}\s+\d{2,4}\b", r"\b\d{8}\b"]:
            m = re.search(patt, raw)
            if m:
                place = (raw[:m.start()] + " " + raw[m.end():]).strip()
                break
    place = re.sub(r"\b(DATE OF BIRTH|DATA DI NASCITA|PLACE OF BIRTH|LUOGO DI NASCITA|GEBURTSTAG|GEBURTSORT)\b", " ", place)
    place = re.sub(r"\b(HEIGHT|STATURA|SEX|SESSO|NATIONALITY|CITTADINANZA|EMISSIONE|ISSUING)\b.*$", " ", place)
    place = re.sub(r"\s+", " ", place).strip(" :-/")
    return {"place": place, "date": date}


def sanitize_place_value(value: str) -> str:
    value = normalize(value)
    if not value:
        return ""
    value = strip_leading_markers(value)
    if re.fullmatch(r"\d{1,3}\s*CM", value):
        return ""
    value = re.sub(r"\b(HEIGHT|STATURA|SEX|SESSO|EMISSIONE|ISSUING|NATIONALITY|CITTADINANZA|DOCUMENT NO|DOCUMENT NUMBER|AUTHORITY|ISSUING AUTHORITY)\b.*$", " ", value)
    value = re.sub(r"\s+", " ", value).strip(" :-/")
    value = re.sub(r"\b(ITALY|ITALIA|NORWAY|NORVEGIA|GERMANIA|GERMANY|FRANCE|FRANCIA)$", lambda m: m.group(0), value)
    if parse_date(value):
        return ""
    return value


def maybe_attach_province(value: str) -> str:
    value = sanitize_place_value(value)
    if not value:
        return ""
    m = re.match(r"^(.+?)\s+([A-Z]{2})$", value)
    if m and len(m.group(1).strip()) > 2:
        return f"{m.group(1).strip()} ({m.group(2)})"
    return value


def pick_document_number(text_norm: str, doc_type: str) -> str:
    patterns = []
    if doc_type == "CARTA DI IDENTITA'":
        patterns = [
            r"\b([A-Z]{2}\d{5}[A-Z]{2})\b",
            r"\b([A-Z]{2}\d{5,6}[A-Z]{1,2})\b",
            r"\b([A-Z]\d[A-Z0-9]{7})\b",
        ]
    elif doc_type == "PATENTE DI GUIDA":
        patterns = [r"\b([A-Z]{1,3}\d{5,8}[A-Z]{0,2})\b"]
    elif doc_type == "PASSAPORTO ORDINARIO":
        patterns = [r"\b([A-Z0-9]{8,9})\b", r"\b([A-Z0-9]{7})\b"]
    else:
        patterns = [r"\b([A-Z0-9]{6,12})\b"]
    value = first_match(patterns, text_norm)
    return value.replace(" ", "")


def country_from_profile(countries: List[Country], profile: str) -> Optional[Country]:
    name = DOC_PROFILE_TO_COUNTRY.get(profile, "")
    return find_country(countries, name) if name else None


def looks_like_address(value: str) -> bool:
    v = normalize(value)
    if not v:
        return False
    if any(word in v.split() for word in ADDRESS_WORDS):
        return True
    if "," in value or re.search(r"\b\d{1,4}[A-Z]?\b", v):
        return True
    return False


def generic_document_number(lines_norm: List[str], profile: str) -> str:
    for line in lines_norm[:8]:
        if profile == "de_id":
            m = re.search(r"\b([A-Z0-9]{9})\b", line)
            if m:
                return m.group(1)
        if profile == "generic_id":
            m = re.search(r"\b([A-Z0-9]{6,12})\b", line)
            if m:
                return m.group(1)
    return ""


def collect_profile_fields(front_lines: List[str], back_lines: List[str], mrz: Dict[str, str], profile: str, countries: List[Country]) -> Dict[str, str]:
    front_norm = normalize_lines(front_lines)
    back_norm = normalize_lines(back_lines)
    combined_norm = front_norm + back_norm
    out: Dict[str, str] = {}

    if profile == "it_cie":
        birth_combo = extract_it_cie_birth_combo(front_norm)
        split = split_place_and_date(birth_combo)
        issue_place = extract_it_cie_issue_place(front_norm)
        residence_value = extract_it_cie_residence(back_norm)
        out.update({
            "doc_type": "CARTA DI IDENTITA'",
            "last_name": clean_name_value(mrz.get("last_name", "") or extract_value_by_labels(front_norm, ["COGNOME SURNAME", "COGNOME", "SURNAME"], lookahead=2)),
            "first_name": clean_name_value(mrz.get("first_name", "") or extract_value_by_labels(front_norm, ["NOME NAME", "NOME", "NAME"], lookahead=2)),
            "gender": parse_gender(extract_value_by_labels(front_norm, ["SESSO SEX", "SESSO", "SEX"], lookahead=1) or mrz.get("gender", "")),
            "birth_date": parse_date(birth_combo) or mrz_birth_to_iso(mrz.get("birth_date_mrz", "")),
            "birth_place_raw": maybe_attach_province(split.get("place", "")),
            "citizenship_raw": extract_value_by_labels(front_norm, ["CITTADINANZA NATIONALITY", "CITTADINANZA", "NATIONALITY"], lookahead=1) or "ITALIA",
            "issue_place_raw": issue_place,
            "residence_raw": residence_value,
            "document_number": mrz.get("document_number", "") or pick_document_number("\n".join(front_norm + back_norm), "CARTA DI IDENTITA'"),
        })
    elif profile == "fr_id":
        out.update({
            "doc_type": "CARTA DI IDENTITA'",
            "last_name": clean_name_value(extract_value_by_labels(front_norm, ["NOM SURNAME", "SURNAME", "NOM"], lookahead=2)),
            "first_name": clean_name_value(extract_value_by_labels(front_norm, ["PRENOMS GIVEN NAMES", "GIVEN NAMES", "PRENOMS"], lookahead=2)),
            "gender": parse_gender(extract_value_by_labels(front_norm, ["SEXE SEX", "SEX", "SEXE"], lookahead=1)),
            "birth_date": parse_date(extract_value_by_labels(front_norm, ["DATE DE NAISS DATE OF BIRTH", "DATE OF BIRTH", "DATE DE NAISS"], lookahead=1, allow_date=True)),
            "birth_place_raw": sanitize_place_value(extract_value_by_labels(front_norm, ["LIEU DE NAISSANCE PLACE OF BIRTH", "PLACE OF BIRTH", "LIEU DE NAISSANCE"], lookahead=1)),
            "citizenship_raw": extract_value_by_labels(front_norm, ["NATIONALITE NATIONALITY", "NATIONALITY", "NATIONALITE"], lookahead=1) or "FRANCIA",
            "document_number": extract_value_by_labels(front_norm, ["N DU DOCUMENT DOCUMENT NO", "DOCUMENT NO", "N DU DOCUMENT"], lookahead=1),
        })
    elif profile == "de_id":
        out.update({
            "doc_type": "CARTA DI IDENTITA'",
            "last_name": clean_name_value(extract_value_by_labels(front_norm, ["A NAME SURNAME NOM", "NAME SURNAME NOM", "SURNAME", "NAME SURNAME"], lookahead=2)),
            "birth_name": clean_name_value(extract_value_by_labels(front_norm, ["B GEBURTSNAME NAME AT BIRTH NOM DE NAISSANCE", "NAME AT BIRTH", "GEBURTSNAME"], lookahead=2)),
            "first_name": clean_name_value(extract_value_by_labels(front_norm, ["VORNAMEN GIVEN NAMES PRENOMS", "GIVEN NAMES", "VORNAMEN"], lookahead=2)),
            "birth_date": parse_date(extract_value_by_labels(front_norm, ["GEBURTSTAG DATE OF BIRTH DATE DE NAISSANCE", "DATE OF BIRTH", "GEBURTSTAG"], lookahead=1, allow_date=True)),
            "birth_place_raw": sanitize_place_value(extract_value_by_labels(front_norm, ["GEBURTSORT PLACE OF BIRTH LIEU DE NAISSANCE", "PLACE OF BIRTH", "GEBURTSORT"], lookahead=1)),
            "citizenship_raw": extract_value_by_labels(front_norm, ["STAATSANGEHORIGKEIT NATIONALITY NATIONALITE", "NATIONALITY", "STAATSANGEHORIGKEIT"], lookahead=1) or "GERMANIA",
            "document_number": generic_document_number(front_norm, profile),
        })
    elif profile == "no_passport":
        birth_combo = extract_value_by_labels(front_norm, ["FODSELSDATO DATE OF BIRTH", "DATE OF BIRTH", "FODSELSDATO"], lookahead=1, allow_date=True)
        out.update({
            "doc_type": "PASSAPORTO ORDINARIO",
            "last_name": clean_name_value(extract_value_by_labels(front_norm, ["ETTERNAVN SURNAME", "SURNAME", "ETTERNAVN"], lookahead=2) or mrz.get("last_name", "")),
            "first_name": clean_name_value(extract_value_by_labels(front_norm, ["FORNAVN FIRST AND MIDDLE NAMES", "FIRST AND MIDDLE NAMES", "FORNAVN"], lookahead=2) or mrz.get("first_name", "")),
            "gender": parse_gender(extract_value_by_labels(front_norm, ["KJONN SEX", "SEX", "KJONN"], lookahead=1) or mrz.get("gender", "")),
            "birth_date": parse_date(birth_combo) or mrz_birth_to_iso(mrz.get("birth_date_mrz", "")),
            "birth_place_raw": sanitize_place_value(extract_value_by_labels(front_norm, ["FODESTED PLACE OF BIRTH", "PLACE OF BIRTH", "FODESTED"], lookahead=1)),
            "citizenship_raw": extract_value_by_labels(front_norm, ["NASJONALITET NATIONALITY", "NATIONALITY", "NASJONALITET"], lookahead=1) or mrz.get("citizenship_alpha3", "") or "NORVEGIA",
            "issue_place_raw": sanitize_place_value(extract_value_by_labels(front_norm, ["UTSTEDENDE MYNDIGHHET ISSUING AUTHORITY", "ISSUING AUTHORITY", "UTSTEDENDE MYNDIGHHET", "UTSTEDENDE MYNDIGHET"], lookahead=2)),
            "document_number": mrz.get("document_number", "") or extract_value_by_labels(front_norm, ["PASSNUMMER PASSPORT NUMBER", "PASSPORT NUMBER", "PASSNUMMER"], lookahead=1),
        })
    elif profile == "generic_id":
        out.update({
            "doc_type": "CARTA DI IDENTITA'",
            "full_name": clean_name_value(extract_value_by_labels(front_norm, ["FULL NAME NOMBRE COMPLETO", "FULL NAME", "NOMBRE COMPLETO"], lookahead=2)),
            "birth_date": parse_date(extract_value_by_labels(front_norm, ["DOB", "DATE OF BIRTH", "BIRTH"], lookahead=1, allow_date=True)),
            "gender": parse_gender(extract_value_by_labels(front_norm, ["GENDER", "SEXO", "SEX"], lookahead=1)),
            "citizenship_raw": extract_value_by_labels(front_norm, ["COUNTRY", "NATIONALITY", "PAIS"], lookahead=1),
            "residence_raw": extract_value_by_labels(front_norm, ["RESIDENCE ADDRESS", "ADDRESS", "RESIDENCE"], lookahead=2),
            "document_number": generic_document_number(front_norm, profile),
        })
    else:
        out.update({
            "doc_type": detect_document_type("\n".join(combined_norm)),
            "last_name": clean_name_value(extract_value_by_labels(combined_norm, ["COGNOME SURNAME", "NOM SURNAME", "SURNAME", "COGNOME", "ETTERNAVN SURNAME", "ETTERNAVN"], lookahead=2) or mrz.get("last_name", "")),
            "first_name": clean_name_value(extract_value_by_labels(combined_norm, ["NOME NAME", "PRENOMS GIVEN NAMES", "GIVEN NAMES", "FIRST AND MIDDLE NAMES", "VORNAMEN GIVEN NAMES PRENOMS", "FORNAVN FIRST AND MIDDLE NAMES", "NAME", "NOME", "PRENOMS", "FORNAVN", "VORNAMEN"], lookahead=2) or mrz.get("first_name", "")),
            "gender": parse_gender(extract_value_by_labels(combined_norm, ["SESSO SEX", "SEXE SEX", "SEX", "KJONN SEX", "SESSO", "SEXE", "KJONN"], lookahead=1) or mrz.get("gender", "")),
            "birth_date": parse_date(extract_value_by_labels(combined_norm, ["DATE OF BIRTH", "DATA DI NASCITA", "DATE DE NAISS", "GEBURTSTAG", "FODSELSDATO"], lookahead=1, allow_date=True)) or mrz_birth_to_iso(mrz.get("birth_date_mrz", "")),
            "birth_place_raw": sanitize_place_value(extract_value_by_labels(combined_norm, ["PLACE OF BIRTH", "LUOGO DI NASCITA", "LIEU DE NAISSANCE", "GEBURTSORT", "FODESTED"], lookahead=2)),
            "citizenship_raw": extract_value_by_labels(combined_norm, ["NATIONALITY", "CITTADINANZA", "NATIONALITE", "STAATSANGEHORIGKEIT", "NASJONALITET"], lookahead=1) or mrz.get("citizenship_alpha3", ""),
            "residence_raw": extract_value_by_labels(combined_norm, ["INDIRIZZO DI RESIDENZA", "RESIDENCE ADDRESS", "RESIDENCE", "ADDRESS"], lookahead=2),
            "issue_place_raw": sanitize_place_value(extract_value_by_labels(combined_norm, ["COMUNE DI MUNICIPALITY", "MUNICIPALITY", "ISSUING AUTHORITY", "AUTORITA", "AUTHORITY", "COMUNE DI RILASCIO"], lookahead=2)),
            "document_number": mrz.get("document_number", "") or pick_document_number("\n".join(combined_norm), detect_document_type("\n".join(combined_norm))),
        })

    return {k: v for k, v in out.items() if v}


def resolve_birth_place(raw_place: str, countries: List[Country], comuni: List[Comune], explicit_country: Optional[Country] = None) -> Dict[str, str]:
    raw_place = maybe_attach_province(str(raw_place or "").strip())
    if not raw_place:
        return {}

    m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", raw_place.strip(), re.IGNORECASE)
    province = m.group(2).upper() if m else ""
    comune_value = m.group(1).strip() if m else raw_place
    comune = find_comune(comuni, comune_value, province)
    if comune:
        return {
            "birth_state_label": ITALY_CODE,
            "birth_province": comune.province,
            "birth_place_label": comune.code,
            "birth_city_code": comune.code,
        }

    country = find_country(countries, raw_place) or explicit_country
    if country:
        payload = {
            "birth_state_label": country.code,
            "birth_province": "",
            "birth_city_code": "",
        }
        if country.code != ITALY_CODE:
            payload["birth_place_label"] = raw_place
        return payload
    return {}


def resolve_residence_place(raw_place: str, countries: List[Country], comuni: List[Comune], explicit_state: Optional[Country]) -> Dict[str, str]:
    raw_place = str(raw_place or "").strip()
    if not raw_place:
        return {}

    candidate = maybe_attach_province(raw_place)
    m = re.match(r"^(.+?)\s*\(([A-Z]{2})\)$", candidate.strip(), re.IGNORECASE)
    province = m.group(2).upper() if m else ""
    comune_value = m.group(1).strip() if m else candidate
    comune = find_comune(comuni, comune_value, province)
    if comune:
        return {
            "residence_state_label": ITALY_CODE,
            "residence_province": comune.province,
            "residence_place_label": comune.code,
            "residence_place_code": comune.code,
        }

    if explicit_state and explicit_state.code != ITALY_CODE:
        return {
            "residence_state_label": explicit_state.code,
            "residence_province": "",
            "residence_place_label": raw_place,
            "residence_place_code": raw_place,
        }

    country = find_country(countries, raw_place)
    if country and country.code != ITALY_CODE:
        return {
            "residence_state_label": country.code,
            "residence_province": "",
            "residence_place_label": raw_place,
            "residence_place_code": raw_place,
        }

    return {}


def resolve_issue_place(raw_place: str, countries: List[Country], comuni: List[Comune]) -> str:
    raw_place = maybe_attach_province(str(raw_place or "").strip())
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
    return title_case(raw_place)


def merge_missing(target: Dict[str, str], source: Dict[str, str]) -> None:
    for key, value in source.items():
        if value and not target.get(key):
            target[key] = value


def map_document(payload: dict, data_dir: Path) -> dict:
    countries = load_countries(data_dir)
    comuni = load_comuni(data_dir)

    front = payload.get("front") or {}
    back = payload.get("back") or {}
    docs = [front, back]
    front_text = extract_document_text(front) if front else ""
    back_text = extract_document_text(back) if back else ""
    combined_text = "\n".join([part for part in [front_text, back_text] if part])
    front_lines = prepare_lines(front_text)
    back_lines = prepare_lines(back_text)
    lines = prepare_lines(combined_text)
    lines_norm = normalize_lines(lines)
    text_norm = "\n".join(lines_norm)
    front_entities = extract_entities(front) if front else {}
    back_entities = extract_entities(back) if back else {}
    entities: Dict[str, List[str]] = {}
    for source in (front_entities, back_entities):
        for key, values in source.items():
            entities.setdefault(key, [])
            for value in values:
                if value not in entities[key]:
                    entities[key].append(value)

    warnings: List[str] = []
    extracted: Dict[str, str] = {}
    display: Dict[str, str] = {}

    mrz = parse_mrz(lines)
    profile = detect_document_profile(text_norm)
    profile_country = country_from_profile(countries, profile)

    custom_fields = collect_custom_processor_fields(front_entities, back_entities)
    profile_fields = collect_profile_fields(front_lines, back_lines, mrz, profile, countries)
    fallback_fields = collect_profile_fields(front_lines, back_lines, mrz, "generic", countries)
    fields: Dict[str, str] = {}
    merge_missing(fields, custom_fields)
    merge_missing(fields, profile_fields)
    merge_missing(fields, fallback_fields)

    doc_type = fields.get("doc_type") or detect_document_type(text_norm)
    if not doc_type and profile in {"it_cie", "fr_id", "de_id", "generic_id"}:
        doc_type = "CARTA DI IDENTITA'"
    if not doc_type and profile == "no_passport":
        doc_type = "PASSAPORTO ORDINARIO"

    last_name = fields.get("last_name", "")
    first_name = fields.get("first_name", "")
    if fields.get("full_name") and (not last_name or not first_name):
        parts = [p for p in re.split(r"\s+", fields["full_name"].strip()) if p]
        if len(parts) >= 2:
            last_name = last_name or parts[-1]
            first_name = first_name or " ".join(parts[:-1])
    if mrz.get("last_name") and mrz.get("first_name") and (not last_name or not first_name or normalize(first_name) == normalize(last_name)):
        last_name = last_name or mrz.get("last_name", "")
        first_name = mrz.get("first_name", "") if (not first_name or normalize(first_name) == normalize(last_name)) else first_name
    if normalize(first_name) == normalize(last_name):
        alt_first = clean_name_value(extract_value_by_labels(normalize_lines(front_lines + back_lines), ["PRENOMS GIVEN NAMES", "GIVEN NAMES", "VORNAMEN GIVEN NAMES PRENOMS", "FORNAVN FIRST AND MIDDLE NAMES", "NOME NAME", "NOME", "NAME"], lookahead=2))
        if alt_first and normalize(alt_first) != normalize(last_name):
            first_name = alt_first

    gender = fields.get("gender", "") or parse_gender(mrz.get("gender", ""))
    birth_date = fields.get("birth_date", "") or mrz_birth_to_iso(mrz.get("birth_date_mrz", ""))

    citizenship_raw = fields.get("citizenship_raw", "")
    citizenship = find_country(countries, citizenship_raw) if citizenship_raw else None
    if not citizenship and mrz.get("citizenship_alpha3"):
        citizenship = alpha3_to_country(countries, mrz.get("citizenship_alpha3", ""))
    if not citizenship and profile_country:
        citizenship = profile_country
        warnings.append("Cittadinanza dedotta dal tipo/paese del documento: verifica prima del salvataggio.")

    birth_place_raw = sanitize_place_value(fields.get("birth_place_raw", ""))
    birth_place_map = resolve_birth_place(birth_place_raw, countries, comuni, citizenship or profile_country)

    residence_raw = fields.get("residence_raw", "")
    residence_parts = split_address_and_locality(residence_raw)
    residence_state_raw = fields.get("residence_state_raw", "")
    residence_state = find_country(countries, residence_state_raw) if residence_state_raw else None
    residence_lookup_value = residence_parts.get("locality") or residence_raw
    residence_map = {}
    if residence_lookup_value and not looks_like_address(residence_lookup_value):
        residence_map = resolve_residence_place(residence_lookup_value, countries, comuni, residence_state or profile_country)

    issue_place_raw = sanitize_place_value(fields.get("issue_place_raw", ""))
    if profile == "it_cie" and issue_place_raw and (not residence_map) and residence_raw and looks_like_street_address(residence_raw):
        residence_map = resolve_residence_place(issue_place_raw, countries, comuni, residence_state or profile_country)
    issue_place_code = resolve_issue_place(issue_place_raw, countries, comuni)

    document_number = fields.get("document_number", "") or mrz.get("document_number", "") or pick_document_number(text_norm, doc_type)

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

    if fields.get("issue_date"):
        display["Emissione"] = fields["issue_date"]
    if fields.get("expiry_date"):
        display["Scadenza"] = fields["expiry_date"]
    if fields.get("fiscal_code"):
        display["Codice fiscale"] = fields["fiscal_code"]

    extracted.update({k: v for k, v in birth_place_map.items() if v})
    if birth_place_map.get("birth_province"):
        display["Provincia nascita"] = birth_place_map["birth_province"]
    if birth_place_map.get("birth_city_code"):
        comune = find_comune(comuni, birth_place_map["birth_city_code"])
        if comune:
            display["Comune nascita"] = comune.label
    elif birth_place_raw:
        display["Luogo nascita"] = title_case(birth_place_raw)

    extracted.update({k: v for k, v in residence_map.items() if v})
    if residence_map.get("residence_province"):
        display["Provincia residenza"] = residence_map["residence_province"]
    if residence_map.get("residence_place_code") and residence_map.get("residence_state_label") == ITALY_CODE:
        comune = find_comune(comuni, residence_map["residence_place_code"])
        if comune:
            display["Comune residenza"] = comune.label
    elif residence_raw:
        label = "Indirizzo residenza" if looks_like_street_address(residence_raw) else "Residenza"
        display[label] = title_case(residence_raw)
    if residence_parts.get("address") and residence_parts.get("address") != residence_raw:
        display["Indirizzo residenza"] = title_case(residence_parts["address"])

    if issue_place_code:
        extracted["document_issue_place"] = issue_place_code
        display["Luogo rilascio"] = issue_place_code
    elif issue_place_raw:
        warnings.append("Luogo di rilascio documento trovato ma non riconosciuto automaticamente: verifica il campo manualmente.")

    if residence_raw and not residence_map:
        if looks_like_street_address(residence_raw):
            warnings.append("Residenza rilevata come indirizzo completo: verifica manualmente comune/località nel form.")
        else:
            warnings.append("Residenza rilevata ma non riconosciuta automaticamente: completa il comune/località manualmente.")
    if not birth_place_map and birth_place_raw:
        warnings.append("Luogo di nascita rilevato ma non riconosciuto automaticamente: completa provincia/comune o stato manualmente.")
    if not doc_type:
        warnings.append("Tipo documento non riconosciuto con certezza: selezionalo manualmente.")
    if not document_number:
        warnings.append("Numero documento non rilevato con sicurezza.")
    if normalize(first_name) == normalize(last_name) and first_name:
        warnings.append("Nome e cognome risultano uguali: verifica i campi estratti dal documento.")

    return {
        "profile": profile,
        "form_payload": extracted,
        "display_payload": display,
        "warnings": warnings,
        "raw": {
            "birth_place": birth_place_raw,
            "residence_place": residence_raw,
            "issue_place": issue_place_raw,
            "citizenship": citizenship_raw,
            "fields": fields,
            "entities": entities,
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
