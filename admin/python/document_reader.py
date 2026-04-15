#!/usr/bin/env python3
import os

os.environ["OPENBLAS_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"
os.environ["MKL_NUM_THREADS"] = "1"
os.environ["NUMEXPR_NUM_THREADS"] = "1"

import argparse
import json
import re
import sys
from typing import Any, Dict, List, Optional


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Read ID document images and extract strong fields.")
    parser.add_argument("--front", help="Path to front image")
    parser.add_argument("--back", help="Path to back image")
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


def mrz_date_to_iso(value: Any) -> Optional[str]:
    if value is None:
        return None

    text = str(value).strip()
    if not text:
        return None

    if re.fullmatch(r"\d{4}-\d{2}-\d{2}", text):
        return text

    if re.fullmatch(r"\d{6}", text):
        yy = int(text[:2])
        mm = text[2:4]
        dd = text[4:6]
        year = 1900 + yy if yy > 30 else 2000 + yy
        return f"{year:04d}-{mm}-{dd}"

    return None


def add_field(fields: Dict[str, Dict[str, Any]], key: str, value: Any, source: str, confidence: float) -> None:
    if value is None:
        return
    text = str(value).strip()
    if not text:
        return
    fields[key] = {
        "value": text,
        "source": source,
        "confidence": confidence,
    }


def preprocess_with_opencv(path: str) -> str:
    try:
        import cv2  # type: ignore
    except Exception:
        return path

    image = cv2.imread(path)

    if image is None:
        raise RuntimeError(f"Impossibile leggere l'immagine: {path}")

    height, width = image.shape[:2]
    max_width = 1600

    if width > max_width:
        scale = max_width / float(width)
        new_width = int(width * scale)
        new_height = int(height * scale)
        image = cv2.resize(image, (new_width, new_height), interpolation=cv2.INTER_AREA)

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    filtered = cv2.bilateralFilter(gray, 9, 75, 75)
    thresh = cv2.adaptiveThreshold(filtered, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 11)

    out_path = path + ".preprocessed.png"
    cv2.imwrite(out_path, thresh)
    return out_path if os.path.exists(out_path) else path


def extract_with_passporteye(paths: List[str], fields: Dict[str, Dict[str, Any]], warnings: List[str], diagnostics: Dict[str, Any]) -> bool:
    try:
        from passporteye import read_mrz  # type: ignore
    except Exception as exc:
        diagnostics.setdefault("missing", []).append(f"passporteye: {exc}")
        return False

    found = False

    for original_path in paths:
        if not original_path:
            continue

        candidate = preprocess_with_opencv(original_path)
        try:
            mrz = read_mrz(candidate, save_roi=True)
        except TypeError:
            mrz = read_mrz(candidate)
        except Exception as exc:
            warnings.append(f"Lettura MRZ non riuscita su {os.path.basename(original_path)}: {exc}")
            continue

        if not mrz:
            continue

        found = True
        diagnostics.setdefault("engines", []).append("passporteye")

        add_field(fields, "document_type", getattr(mrz, "type", None), "mrz", 0.98)
        add_field(fields, "last_name", getattr(mrz, "surname", None), "mrz", 0.99)
        names = getattr(mrz, "names", None)
        if names:
            add_field(fields, "first_name", " ".join(str(names).split()), "mrz", 0.99)
        add_field(fields, "document_number", getattr(mrz, "number", None), "mrz", 0.98)
        add_field(fields, "citizenship_label", getattr(mrz, "nationality", None), "mrz", 0.97)
        add_field(fields, "gender", getattr(mrz, "sex", None), "mrz", 0.97)
        add_field(fields, "birth_date", mrz_date_to_iso(getattr(mrz, "date_of_birth", None)), "mrz", 0.98)
        add_field(fields, "document_expiry_date", mrz_date_to_iso(getattr(mrz, "expiration_date", None)), "mrz", 0.97)

        if fields:
            break

    return found


def extract_with_tesseract(paths: List[str], fields: Dict[str, Dict[str, Any]], warnings: List[str], diagnostics: Dict[str, Any]) -> bool:
    try:
        import pytesseract  # type: ignore
        from PIL import Image  # type: ignore
    except Exception as exc:
        diagnostics.setdefault("missing", []).append(f"pytesseract/Pillow: {exc}")
        return False

    any_text = False
    for original_path in paths:
        if not original_path:
            continue

        candidate = preprocess_with_opencv(original_path)
        try:
            text = pytesseract.image_to_string(Image.open(candidate), lang="eng+ita")
        except Exception as exc:
            warnings.append(f"OCR non riuscito su {os.path.basename(original_path)}: {exc}")
            continue

        if not text:
            continue

        any_text = True
        diagnostics.setdefault("engines", []).append("pytesseract")

        normalized = re.sub(r"\s+", " ", text.upper())
        if "CARTA" in normalized or "IDENTIT" in normalized:
            add_field(fields, "document_type", "carta_identita", "ocr", 0.70)
        elif "PASSPORT" in normalized or "PASSAPORTO" in normalized:
            add_field(fields, "document_type", "passaporto", "ocr", 0.70)

        if "COMUNE DI " in normalized:
            match = re.search(r"COMUNE DI\s+([A-Z'\- ]{2,40})", normalized)
            if match:
                add_field(fields, "document_issue_place", match.group(1).strip(), "ocr", 0.62)

        for date_match in re.findall(r"\b(\d{2}[./-]\d{2}[./-]\d{4})\b", text):
            parts = re.split(r"[./-]", date_match)
            iso = f"{parts[2]}-{parts[1]}-{parts[0]}"
            if "document_issue_date" not in fields:
                add_field(fields, "document_issue_date", iso, "ocr", 0.55)
            elif "document_expiry_date" not in fields:
                add_field(fields, "document_expiry_date", iso, "ocr", 0.55)

        if fields:
            break

    return any_text


def main() -> None:
    args = parse_args()
    paths = [p for p in [args.back, args.front] if p]

    if not paths:
        result(False, "Nessuna immagine ricevuta.")
        return

    diagnostics: Dict[str, Any] = {"paths": paths, "engines": [], "missing": []}
    warnings: List[str] = []
    fields: Dict[str, Dict[str, Any]] = {}

    mrz_found = extract_with_passporteye(paths, fields, warnings, diagnostics)

    if not mrz_found:
        warnings.append("MRZ non rilevata: provo con OCR generico sui campi visivi.")
        extract_with_tesseract(paths, fields, warnings, diagnostics)

    diagnostics["engines"] = list(dict.fromkeys(diagnostics.get("engines", [])))
    diagnostics["missing"] = list(dict.fromkeys(diagnostics.get("missing", [])))

    if not fields:
        install_hint = "Installa almeno passporteye, opencv-python-headless, pillow, pytesseract e il binario tesseract-ocr sul server."
        if diagnostics["missing"]:
            warnings.append(install_hint)
        result(False, "Nessun campo affidabile estratto dal documento.", warnings=warnings, diagnostics=diagnostics)
        return

    result(True, "Campi estratti con successo.", fields=fields, warnings=warnings, diagnostics=diagnostics)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        result(False, f"Errore interno nel parser Python: {exc}", diagnostics={"engines": [], "missing": []})
        sys.exit(0)
