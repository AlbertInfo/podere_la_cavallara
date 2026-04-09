#!/usr/bin/env python3
import json
import re
import sys
from typing import List, Dict, Any, Optional, Tuple

import fitz  # PyMuPDF


LANGUAGES = {
    "Italiano",
    "Inglese",
    "Tedesco",
    "Ceco",
    "Polacco",
    "Olandese",
    "Francese",
    "Spagnolo",
}

LANGUAGE_TO_FLAG = {
    "Italiano": "🇮🇹",
    "Inglese": "🇬🇧",
    "Tedesco": "🇩🇪",
    "Ceco": "🇨🇿",
    "Polacco": "🇵🇱",
    "Olandese": "🇳🇱",
    "Francese": "🇫🇷",
    "Spagnolo": "🇪🇸",
}

PDF_STATE_LABELS = {
    "new": "Nuova prenotazione",
    "existing": "Prenotazione esistente",
    "modified": "Modifica prenotazione esistente",
    "cancelled": "Prenotazione cancellata",
}


def clean(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "")).strip()


def is_date(s: str) -> bool:
    return bool(re.fullmatch(r"\d{2}/\d{2}/\d{4}", clean(s)))


def is_property_code(s: str) -> bool:
    return bool(re.fullmatch(r"IT\d+\.\d+\.\d+", clean(s), re.I))


def is_reference(s: str) -> bool:
    return bool(re.fullmatch(r"\d{9,15}", clean(s)))


def is_language(s: str) -> bool:
    return clean(s) in LANGUAGES


def is_phone(s: str) -> bool:
    s = clean(s)
    if not s:
        return False
    return s.startswith("+")


def is_people(s: str) -> bool:
    s = clean(s)
    if s == "-":
        return True
    return bool(re.search(r"\d+\s*adulti?", s, re.I))


def parse_people(s: str) -> Tuple[int, int]:
    s = clean(s).lower()
    if s in ("", "-"):
        return 0, 0

    adults = 0
    children = 0

    m = re.search(r"(\d+)\s*adulti?", s)
    if m:
        adults = int(m.group(1))

    m = re.search(r"(\d+)\s*bambin[io]", s)
    if m:
        children = int(m.group(1))

    return adults, children


def map_room_type(raw_property: str) -> str:
    n = clean(raw_property).lower()

    if re.search(r"n[°ºo\.\s]*1\b", n) or "(bol561)" in n:
        return "Casa Domenico 1"
    if re.search(r"n[°ºo\.\s]*2\b", n) or "(bol560)" in n:
        return "Casa Domenico 2"
    if re.search(r"n[°ºo\.\s]*3\b", n) or "(bol563)" in n:
        return "Casa Riccardo 3"
    if re.search(r"n[°ºo\.\s]*4\b", n) or "(bol562)" in n:
        return "Casa Riccardo 4"
    if re.search(r"n[°ºo\.\s]*5\b", n) or "(bol565)" in n:
        return "Casa Alessandro 5"
    if re.search(r"n[°ºo\.\s]*6\b", n) or "(bol564)" in n:
        return "Casa Alessandro 6"

    return ""


def language_to_flag(language: str) -> str:
    return LANGUAGE_TO_FLAG.get(clean(language), "")


def extract_page_lines(page) -> List[Dict[str, Any]]:
    """
    Estrae le linee testo della pagina in ordine verticale/orizzontale,
    mantenendo anche le coordinate y per poter agganciare lo stato icona.
    """
    raw = page.get_text("dict")
    items: List[Dict[str, Any]] = []

    for block in raw.get("blocks", []):
        if block.get("type") != 0:
            continue

        for line in block.get("lines", []):
            spans = line.get("spans", [])
            if not spans:
                continue

            text = clean(" ".join(span.get("text", "") for span in spans))
            if not text:
                continue

            bbox = line.get("bbox", [0, 0, 0, 0])
            x0, y0, x1, y1 = bbox
            items.append({
                "text": text,
                "x0": float(x0),
                "y0": float(y0),
                "x1": float(x1),
                "y1": float(y1),
            })

    items.sort(key=lambda item: (round(item["y0"], 1), round(item["x0"], 1)))

    filtered: List[Dict[str, Any]] = []

    for item in items:
        line = item["text"]
        low = line.lower()

        if line.startswith("Nuova prenotazione ("):
            continue
        if line.startswith("Prenotazione esistente ("):
            continue
        if line.startswith("Modifica a prenotazione esistente ("):
            continue
        if line.startswith("Prenotazione cancellata ("):
            continue
        if line.startswith("Pagina IT04151"):
            continue
        if "Lista degli arrivi" in line:
            continue
        if low in {"data casa vacanze clienti dettagli", "data", "casa vacanze", "clienti", "dettagli"}:
            continue
        if line in {
            "Interhome | Service Office",
            "myhome.it@interhome.group",
            "+39 02 4839 1440",
            "Marialia Guarducci Podere La Cavallara",
            "Corso Cavour, 5",
            "01027 MONTEFIASCONE",
            "ITALIA",
            "HHD AG",
            "Sägereistrasse 20",
            "CH-8152 Glattbrugg",
            "Codice partner",
            "Data di creazione Contatto",
            "Data di creazione",
            "Contatto",
        }:
            continue
        if re.match(r"^IT04151\b", line):
            continue

        filtered.append(item)

    return filtered


def clamp(v: int, a: int, b: int) -> int:
    return max(a, min(v, b))


def classify_rgb(r: int, g: int, b: int) -> Optional[str]:
    """
    Classifica il colore dell'icona:
    - verde -> new
    - rosso -> cancelled
    - blu -> modified
    - grigio/scuro -> existing
    """
    # verde acceso
    if g > 130 and r < 150 and b < 150:
        return "new"

    # rosso acceso
    if r > 160 and g < 130 and b < 130:
        return "cancelled"

    # blu
    if b > 150 and r < 150 and g < 190:
        return "modified"

    # grigio scuro / nero
    if abs(r - g) < 25 and abs(g - b) < 25 and r < 120 and g < 120 and b < 120:
        return "existing"

    return None


def detect_pdf_state(page, row_top: float, row_bottom: float) -> str:
    """
    Legge la colonna icona a sinistra campionando i pixel nella zona
    dove si trova lo stato della prenotazione.
    """
    scale = 2.0
    matrix = fitz.Matrix(scale, scale)
    pix = page.get_pixmap(matrix=matrix, alpha=False)

    width = pix.width
    height = pix.height
    channels = pix.n
    samples = pix.samples

    # Regione sinistra dove ricadono le icone nella tabella
    x0 = int(10 * scale)
    x1 = int(55 * scale)

    # Usiamo l'area iniziale della riga, dove compare l'icona
    y0 = int(max(0, (row_top - 2) * scale))
    y1 = int(min(height - 1, (min(row_top + 26, row_bottom + 8)) * scale))

    counts = {
        "new": 0,
        "existing": 0,
        "modified": 0,
        "cancelled": 0,
    }

    if x0 >= width or y0 >= height or x1 <= 0 or y1 <= 0 or y1 <= y0:
        return "existing"

    x0 = clamp(x0, 0, width - 1)
    x1 = clamp(x1, 0, width - 1)
    y0 = clamp(y0, 0, height - 1)
    y1 = clamp(y1, 0, height - 1)

    for y in range(y0, y1 + 1):
        row_offset = y * width * channels
        for x in range(x0, x1 + 1):
            idx = row_offset + x * channels
            r = samples[idx]
            g = samples[idx + 1]
            b = samples[idx + 2]

            state = classify_rgb(r, g, b)
            if state:
                counts[state] += 1

    best_state = max(counts, key=counts.get)
    best_score = counts[best_state]

    if best_score <= 0:
        return "existing"

    return best_state


def parse_page(page, page_no: int, lines: List[Dict[str, Any]]) -> Dict[str, Any]:
    rows: List[Dict[str, Any]] = []
    pending_note = None
    i = 0
    count = len(lines)

    while i < count:
        line = lines[i]["text"]

        if line.lower().startswith("note:"):
            pending_note = line
            i += 1
            continue

        if i + 2 >= count:
            break

        if not is_date(lines[i]["text"]) or not is_date(lines[i + 1]["text"]) or not is_property_code(lines[i + 2]["text"]):
            i += 1
            continue

        row_top = lines[i]["y0"]
        row_bottom = lines[i]["y1"]

        check_in = lines[i]["text"]
        check_out = lines[i + 1]["text"]
        prop_code = lines[i + 2]["text"]
        i += 3

        property_parts: List[str] = []
        while i < count:
            cur = lines[i]["text"]
            row_bottom = max(row_bottom, lines[i]["y1"])

            if cur.lower().startswith("porzione di casa") or re.fullmatch(r"\(BOL\d+\)", cur, re.I):
                property_parts.append(cur)
                i += 1
                continue
            break

        if i >= count:
            break

        customer_name = lines[i]["text"]
        row_bottom = max(row_bottom, lines[i]["y1"])
        i += 1

        people = "-"
        if i < count and is_people(lines[i]["text"]):
            people = lines[i]["text"]
            row_bottom = max(row_bottom, lines[i]["y1"])
            i += 1

        phone = ""
        if i < count and is_phone(lines[i]["text"]):
            phone = lines[i]["text"]
            row_bottom = max(row_bottom, lines[i]["y1"])
            i += 1

        email = ""
        if i < count and "@" in lines[i]["text"]:
            email_parts = [lines[i]["text"]]
            row_bottom = max(row_bottom, lines[i]["y1"])
            i += 1

            while i < count:
                nxt = lines[i]["text"]

                if is_reference(nxt) or is_language(nxt) or is_date(nxt) or is_property_code(nxt):
                    break

                if re.fullmatch(r"[A-Za-z0-9._%+\-]+", nxt):
                    email_parts.append(nxt)
                    row_bottom = max(row_bottom, lines[i]["y1"])
                    i += 1
                    continue

                if nxt.lower().startswith("note:"):
                    break

                break

            email = "".join(email_parts).strip()

        if i >= count or not is_reference(lines[i]["text"]):
            continue

        external_reference = lines[i]["text"]
        row_bottom = max(row_bottom, lines[i]["y1"])
        i += 1

        language = ""
        if i < count and is_language(lines[i]["text"]):
            language = lines[i]["text"]
            row_bottom = max(row_bottom, lines[i]["y1"])
            i += 1

        extra_notes: List[str] = []
        while i < count:
            nxt = lines[i]["text"]
            if is_date(nxt):
                break
            if is_property_code(nxt):
                break
            if is_reference(nxt):
                break
            if is_language(nxt):
                break
            if nxt.lower().startswith("note:"):
                break
            extra_notes.append(nxt)
            row_bottom = max(row_bottom, lines[i]["y1"])
            i += 1

        raw_property = clean(prop_code + " " + " ".join(property_parts))
        adults, children = parse_people(people)

        notes: List[str] = []
        if pending_note:
            notes.append(pending_note)
            pending_note = None
        notes.extend(extra_notes)

        pdf_state = detect_pdf_state(page, row_top, row_bottom)

        rows.append({
            "import_row_id": "",
            "stay_period": f"{check_in} - {check_out}",
            "check_in": check_in,
            "check_out": check_out,
            "room_type": map_room_type(raw_property),
            "customer_name": customer_name,
            "customer_email": email,
            "customer_phone": phone,
            "adults": adults,
            "children_count": children,
            "notes": "\n".join(notes).strip() if notes else None,
            "status": "confermata",
            "source": "interhome_pdf",
            "external_reference": external_reference,
            "_language": language,
            "_country_flag": language_to_flag(language),
            "_raw_people": people,
            "_raw_property": raw_property,
            "_page": page_no,
            "_pdf_state": pdf_state,
            "_pdf_state_label": PDF_STATE_LABELS.get(pdf_state, ""),
        })

    return {"rows": rows}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Percorso PDF mancante"}))
        sys.exit(1)

    pdf_path = sys.argv[1]
    doc = fitz.open(pdf_path)

    all_rows: List[Dict[str, Any]] = []
    pages_read = 0

    for idx, page in enumerate(doc):
        lines = extract_page_lines(page)
        if not lines:
            continue

        pages_read += 1
        parsed = parse_page(page, idx + 1, lines)
        all_rows.extend(parsed["rows"])

    result = {
        "ok": True,
        "summary": {
            "pages_read": pages_read,
            "parsed_total": len(all_rows),
        },
        "rows": all_rows,
    }

    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()