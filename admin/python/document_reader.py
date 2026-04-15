#!/usr/bin/env python3
import os

# Keep CPU/thread usage under control on shared hosting
os.environ.setdefault("OPENBLAS_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("MKL_NUM_THREADS", "1")
os.environ.setdefault("NUMEXPR_NUM_THREADS", "1")
os.environ.setdefault("KMP_DUPLICATE_LIB_OK", "TRUE")

import argparse
import json
import re
import sys
from typing import Any, Dict, List, Optional, Tuple


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Read ID document images and extract strong fields with EasyOCR.")
    parser.add_argument("--front", help="Path to front image")
    parser.add_argument("--back", help="Path to back image")
    parser.add_argument("--langs", default=os.environ.get("EASYOCR_LANGS", "en,it"),
                        help="Comma-separated EasyOCR languages, default en,it")
    parser.add_argument("--model-dir", default=os.environ.get("EASYOCR_MODEL_DIR", ""),
                        help="Optional EasyOCR model directory")
    parser.add_argument("--download-models", action="store_true",
                        default=os.environ.get("EASYOCR_DOWNLOAD_MODELS", "1") == "1",
                        help="Allow EasyOCR to download models if missing")
    return parser.parse_args()


def result(success: bool, message: str, fields: Optional[Dict[str, Dict[str, Any]]] = None,
           warnings: Optional[List[str]] = None, diagnostics: Optional[Dict[str, Any]] = None) -> None:
    payload = {
        "success": success,
        "message": message,
        "fields": fields or {},
        "warnings": warnings or [],
        "diagnostics": diagnostics or {},
    }
    print(json.dumps(payload, ensure_ascii=False))


def add_field(fields: Dict[str, Dict[str, Any]], key: str, value: Any, source: str, confidence: float) -> None:
    if value is None:
        return
    text = str(value).strip()
    if not text:
        return
    current = fields.get(key)
    if current and float(current.get("confidence", 0)) >= confidence:
        return
    fields[key] = {
        "value": text,
        "source": source,
        "confidence": round(float(confidence), 4),
    }


def iso_from_ddmmyyyy(text: str) -> Optional[str]:
    m = re.search(r"\b(\d{2})[./-](\d{2})[./-](\d{4})\b", text)
    if not m:
        return None
    return f"{m.group(3)}-{m.group(2)}-{m.group(1)}"


def mrz_date_to_iso(value: str) -> Optional[str]:
    value = re.sub(r"[^0-9]", "", value or "")
    if len(value) != 6:
        return None
    yy = int(value[:2])
    mm = value[2:4]
    dd = value[4:6]
    year = 1900 + yy if yy > 30 else 2000 + yy
    return f"{year:04d}-{mm}-{dd}"


def normalize_text(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def sanitize_name_piece(value: str) -> str:
    value = re.sub(r"[^A-ZÀ-ÖØ-Ý'\- ]+", " ", value.upper())
    value = re.sub(r"\s+", " ", value).strip()
    return value.title()


def map_document_code(code: str) -> Optional[str]:
    code = (code or "").upper().strip()
    if not code:
        return None
    if code.startswith("P"):
        return "passaporto"
    if code.startswith("I") or code.startswith("ID") or code.startswith("C"):
        return "carta_identita"
    return "altro"


def load_image_for_cv(path: str, max_size: Tuple[int, int] = (1400, 1400)):
    from PIL import Image, ImageOps
    import numpy as np
    import cv2

    try:
        resampling = Image.Resampling.LANCZOS
    except AttributeError:
        resampling = Image.LANCZOS

    with Image.open(path) as img:
        img = ImageOps.exif_transpose(img)
        img = img.convert("RGB")
        img.thumbnail((2200, 2200), resampling)
        return cv2.cvtColor(np.array(img), cv2.COLOR_RGB2BGR)


def preprocess_variants(path: str) -> Dict[str, Any]:
    import cv2

    image = load_image_for_cv(path)
    if image is None:
        raise RuntimeError(f"Impossibile leggere l'immagine: {path}")

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    den = cv2.bilateralFilter(gray, 7, 50, 50)
    thr = cv2.adaptiveThreshold(den, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 9)
    inv = cv2.bitwise_not(thr)

    h, w = gray.shape[:2]
    lower_start = int(h * 0.58)
    mrz_roi = gray[lower_start:h, 0:w]
    mrz_roi = cv2.resize(mrz_roi, None, fx=1.8, fy=1.8, interpolation=cv2.INTER_CUBIC)
    mrz_thr = cv2.adaptiveThreshold(mrz_roi, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 9)

    return {
        "color": image,
        "gray": gray,
        "thr": thr,
        "inv": inv,
        "mrz": mrz_thr,
    }


def get_reader(langs: List[str], model_dir: str = "", download_models: bool = True):
    import easyocr

    kwargs: Dict[str, Any] = {
        "lang_list": langs,
        "gpu": False,
        "download_enabled": download_models,
        "verbose": False,
    }
    if model_dir:
        kwargs["model_storage_directory"] = model_dir
    return easyocr.Reader(**kwargs)


def read_lines(reader: Any, image: Any, allowlist: Optional[str] = None) -> List[Tuple[str, float]]:
    kwargs: Dict[str, Any] = {
        "detail": 1,
        "paragraph": False,
        "decoder": "greedy",
    }
    if allowlist:
        kwargs["allowlist"] = allowlist

    rows = []
    for item in reader.readtext(image, **kwargs):
        try:
            box, text, conf = item
        except ValueError:
            continue
        clean = normalize_text(str(text))
        if not clean:
            continue
        top = min(point[1] for point in box)
        rows.append((top, clean, float(conf)))

    rows.sort(key=lambda x: x[0])
    return [(text, conf) for _, text, conf in rows]


def detect_mrz_lines(lines: List[Tuple[str, float]]) -> List[str]:
    mrz_lines: List[str] = []
    for text, _ in lines:
        candidate = re.sub(r"\s+", "", text.upper())
        candidate = candidate.replace(" «", "<").replace("«", "<")
        candidate = re.sub(r"[^A-Z0-9<]", "", candidate)
        if len(candidate) >= 24 and ("<" in candidate or sum(ch.isdigit() for ch in candidate) >= 6):
            mrz_lines.append(candidate)
    return mrz_lines


def parse_td3(lines: List[str]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if len(lines) < 2:
        return out
    l1 = lines[0][:44].ljust(44, "<")
    l2 = lines[1][:44].ljust(44, "<")

    doc_code = l1[0:2].replace("<", "")
    surname_names = l1[5:].split("<<", 1)
    surname = sanitize_name_piece(surname_names[0].replace("<", " ")) if surname_names else ""
    names = sanitize_name_piece(surname_names[1].replace("<", " ")) if len(surname_names) > 1 else ""

    number = l2[0:9].replace("<", "")
    nationality = l2[10:13].replace("<", "")
    birth_date = mrz_date_to_iso(l2[13:19])
    sex = l2[20:21].replace("<", "")
    expiry = mrz_date_to_iso(l2[21:27])

    if doc_code:
        out["document_type"] = map_document_code(doc_code) or doc_code
    if surname:
        out["last_name"] = surname
    if names:
        out["first_name"] = names
    if number:
        out["document_number"] = number
    if nationality:
        out["citizenship_label"] = nationality
    if birth_date:
        out["birth_date"] = birth_date
    if sex:
        out["gender"] = sex
    if expiry:
        out["document_expiry_date"] = expiry
    return out


def parse_td1(lines: List[str]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if len(lines) < 3:
        return out
    l1 = lines[0][:30].ljust(30, "<")
    l2 = lines[1][:30].ljust(30, "<")
    l3 = lines[2][:30].ljust(30, "<")

    doc_code = l1[0:2].replace("<", "")
    number = l1[5:14].replace("<", "")
    birth_date = mrz_date_to_iso(l2[0:6])
    sex = l2[7:8].replace("<", "")
    expiry = mrz_date_to_iso(l2[8:14])
    nationality = l2[15:18].replace("<", "")

    parts = l3.split("<<", 1)
    surname = sanitize_name_piece(parts[0].replace("<", " ")) if parts else ""
    names = sanitize_name_piece(parts[1].replace("<", " ")) if len(parts) > 1 else ""

    if doc_code:
        out["document_type"] = map_document_code(doc_code) or doc_code
    if number:
        out["document_number"] = number
    if birth_date:
        out["birth_date"] = birth_date
    if sex:
        out["gender"] = sex
    if expiry:
        out["document_expiry_date"] = expiry
    if nationality:
        out["citizenship_label"] = nationality
    if surname:
        out["last_name"] = surname
    if names:
        out["first_name"] = names
    return out


def parse_mrz_from_lines(lines: List[str]) -> Dict[str, str]:
    # Prefer 3-line TD1, then 2-line TD3
    if len(lines) >= 3:
        cand = [line for line in lines if len(line) >= 24][:3]
        if len(cand) == 3:
            parsed = parse_td1(cand)
            if parsed:
                return parsed
    if len(lines) >= 2:
        cand = [line for line in lines if len(line) >= 30][:2]
        if len(cand) == 2:
            parsed = parse_td3(cand)
            if parsed:
                return parsed
    return {}


def extract_by_keywords(lines: List[Tuple[str, float]]) -> Dict[str, Tuple[str, float]]:
    out: Dict[str, Tuple[str, float]] = {}

    patterns = {
        "last_name": [r"\b(cognome|surname|nom|apellido(?:s)?)\b"],
        "first_name": [r"\b(nome|given\s*name(?:s)?|prenom|pr[eé]nom|nombres?)\b"],
        "birth_date": [r"\b(data\s*di\s*nascita|date\s*of\s*birth|geburtsdatum|fecha\s*de\s*nacimiento|date\s*de\s*naissance)\b"],
        "citizenship_label": [r"\b(cittadinanza|citizenship|nationality|nationalit[eé]|nacionalidad|staatsangeh[oö]rigkeit)\b"],
        "document_number": [r"\b(n[.°º]?\s*documento|document\s*no|document\s*number|numero\s*documento|id\s*no|card\s*no|num[eé]ro\s*du\s*document)\b"],
        "document_issue_date": [r"\b(data\s*rilascio|date\s*of\s*issue|issued\s*on|date\s*d[' ]emission|fecha\s*de\s*expedici[oó]n)\b"],
        "document_expiry_date": [r"\b(scadenza|expiry|expires|valid\s*until|date\s*d[' ]expiration|fecha\s*de\s*caducidad)\b"],
        "document_issue_place": [r"\b(luogo\s*di\s*rilascio|issued\s*by|autorit[yà]|authority|autoridad|d[eé]livr[eé]\s*par)\b"],
        "gender": [r"\b(sesso|sex|sexe|sexo|geschlecht)\b"],
    }

    only_text = [text for text, _ in lines]

    for idx, (text, conf) in enumerate(lines):
        upper = text.upper()
        for field, regexes in patterns.items():
            if field in out:
                continue
            if any(re.search(rx, upper, re.IGNORECASE) for rx in regexes):
                candidates = [text]
                if idx + 1 < len(only_text):
                    candidates.append(only_text[idx + 1])
                if idx > 0:
                    candidates.append(only_text[idx - 1])
                value = pick_value_for_field(field, candidates)
                if value:
                    out[field] = (value, conf)

    blob = "\n".join(only_text)

    if "document_number" not in out:
        m = re.search(r"\b[A-Z0-9]{5,20}\b", blob.upper())
        if m:
            out["document_number"] = (m.group(0), 0.45)

    if "document_type" not in out:
        up = blob.upper()
        if "CARTA" in up or "IDENTIT" in up or "IDENTITY CARD" in up:
            out["document_type"] = ("carta_identita", 0.70)
        elif "PASSPORT" in up or "PASSAPORTO" in up:
            out["document_type"] = ("passaporto", 0.70)

    if "gender" not in out:
        m = re.search(r"\b([MF])\b", blob.upper())
        if m:
            out["gender"] = (m.group(1), 0.40)

    return out


def pick_value_for_field(field: str, candidates: List[str]) -> Optional[str]:
    for item in candidates:
        text = normalize_text(item)
        if not text:
            continue

        if field in {"birth_date", "document_issue_date", "document_expiry_date"}:
            iso = iso_from_ddmmyyyy(text)
            if iso:
                return iso

        elif field == "gender":
            m = re.search(r"\b([MF])\b", text.upper())
            if m:
                return m.group(1)

        elif field == "document_number":
            m = re.search(r"\b([A-Z0-9]{5,20})\b", text.upper())
            if m:
                return m.group(1)

        elif field in {"last_name", "first_name", "citizenship_label", "document_issue_place"}:
            cleaned = re.sub(r"^(Cognome|Surname|Nom|Apellido|Nome|Prenom|Prénom|Given Names?|Cittadinanza|Citizenship|Nationality|Luogo di rilascio|Issued by|Authority)[:\s-]+", "", text, flags=re.IGNORECASE)
            cleaned = cleaned.strip(" :-")
            if len(cleaned) >= 2:
                return cleaned

    return None


def run_easyocr(paths: List[str], langs: List[str], model_dir: str, download_models: bool,
                fields: Dict[str, Dict[str, Any]], warnings: List[str], diagnostics: Dict[str, Any]) -> None:
    try:
        reader = get_reader(langs, model_dir=model_dir, download_models=download_models)
    except Exception as exc:
        diagnostics.setdefault("missing", []).append(f"easyocr: {exc}")
        return

    diagnostics.setdefault("engines", []).append("easyocr")

    for path in paths:
        variants = preprocess_variants(path)

        # First pass: generic OCR over a couple of variants
        generic_lines: List[Tuple[str, float]] = []
        for key in ("gray", "thr"):
            try:
                generic_lines.extend(read_lines(reader, variants[key]))
            except Exception as exc:
                warnings.append(f"OCR non riuscito su {os.path.basename(path)} ({key}): {exc}")

        # Deduplicate by text keeping best confidence
        best: Dict[str, float] = {}
        for text, conf in generic_lines:
            if text not in best or conf > best[text]:
                best[text] = conf
        generic_lines = sorted(best.items(), key=lambda x: x[1], reverse=True)

        keyword_fields = extract_by_keywords(generic_lines)
        for key, (value, conf) in keyword_fields.items():
            add_field(fields, key, value, "easyocr", conf)

        # Second pass: MRZ-focused OCR on lower zone, restricted charset
        try:
            mrz_read = read_lines(reader, variants["mrz"], allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ<0123456789")
            mrz_lines = detect_mrz_lines(mrz_read)
            mrz_fields = parse_mrz_from_lines(mrz_lines)
            for key, value in mrz_fields.items():
                add_field(fields, key, value, "mrz_ocr", 0.88)
            if mrz_fields:
                diagnostics.setdefault("engines", []).append("mrz_ocr")
        except Exception as exc:
            warnings.append(f"Analisi MRZ OCR non riuscita su {os.path.basename(path)}: {exc}")

        if fields:
            break


def main() -> None:
    args = parse_args()
    paths = [p for p in [args.back, args.front] if p]
    if not paths:
        result(False, "Nessuna immagine ricevuta.")
        return

    langs = [x.strip() for x in args.langs.split(",") if x.strip()]
    diagnostics: Dict[str, Any] = {"paths": paths, "engines": [], "missing": [], "langs": langs}
    warnings: List[str] = []
    fields: Dict[str, Dict[str, Any]] = {}

    run_easyocr(paths, langs, args.model_dir, args.download_models, fields, warnings, diagnostics)

    diagnostics["engines"] = list(dict.fromkeys(diagnostics.get("engines", [])))
    diagnostics["missing"] = list(dict.fromkeys(diagnostics.get("missing", [])))

    if not fields:
        warnings.append("EasyOCR richiede i suoi modelli; al primo avvio può impiegare tempo o tentare un download.")
        result(False, "Nessun campo affidabile estratto dal documento.", warnings=warnings, diagnostics=diagnostics)
        return

    result(True, "Campi estratti con successo.", fields=fields, warnings=warnings, diagnostics=diagnostics)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        result(False, f"Errore interno nel parser Python: {exc}", diagnostics={"engines": [], "missing": []})
        sys.exit(0)
