#!/usr/bin/env python3
import json
import re
import sys
from typing import List, Dict, Any

import fitz  # PyMuPDF


LANGUAGES = {
    "Italiano", "Inglese", "Tedesco", "Ceco",
    "Polacco", "Olandese", "Francese", "Spagnolo"
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
    if s.startswith("+"):
        return True
    return bool(re.fullmatch(r"[0-9][0-9 \-\/]{5,}", s))


def is_people(s: str) -> bool:
    s = clean(s)
    if s == "-":
        return True
    return bool(re.search(r"\d+\s*adulti?", s, re.I))


def parse_people(s: str):
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
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*1\b", n) or "(bol561)" in n:
        return "Casa Domenico 1"
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*2\b", n) or "(bol560)" in n:
        return "Casa Domenico 2"
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*3\b", n) or "(bol563)" in n:
        return "Casa Riccardo 3"
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*4\b", n) or "(bol562)" in n:
        return "Casa Riccardo 4"
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*5\b", n) or "(bol565)" in n:
        return "Casa Alessandro 5"
    if re.search(r"porzione di casa\s*,?\s*n[°ºo\.]?\s*6\b", n) or "(bol564)" in n:
        return "Casa Alessandro 6"
    return ""


def normalize_email(parts: List[str]) -> str:
    email = "".join(clean(p) for p in parts)
    return clean(email)


def detect_note(line: str):
    m = re.search(r"prenotazione n\.\s*([0-9]{9,15})", line, re.I)
    return m.group(1) if m else None


def extract_page_lines(page) -> List[str]:
    # Estrae linee ordinate top->bottom, left->right
    data = page.get_text("dict")
    items = []

    for block in data.get("blocks", []):
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
            items.append((round(bbox[1], 1), round(bbox[0], 1), text))

    items.sort(key=lambda x: (x[0], x[1]))
    lines = [text for _, _, text in items]

    cleaned = []
    for line in lines:
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
        if low in {"data casa vacanze clienti dettagli", "partner"}:
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
        }:
            continue
        if re.match(r"^IT04151\b", line):
            continue
        cleaned.append(line)

    return cleaned


def parse_page(page_no: int, lines: List[str]) -> Dict[str, Any]:
    rows = []
    notes = {}

    i = 0
    count = len(lines)

    while i < count:
        line = lines[i]

        if line.lower().startswith("note:"):
            ref = detect_note(line)
            if ref:
                notes[ref] = line
            i += 1
            continue

        if not is_date(line):
            i += 1
            continue

        check_in = line
        check_out = lines[i + 1] if i + 1 < count else ""
        prop_code = lines[i + 2] if i + 2 < count else ""

        if not is_date(check_out) or not is_property_code(prop_code):
            i += 1
            continue

        cursor = i + 3
        property_parts = []

        while cursor < count:
            candidate = clean(lines[cursor])
            if not candidate:
                cursor += 1
                continue
            if is_date(candidate) or is_reference(candidate) or is_language(candidate):
                break
            if candidate.lower().startswith("note:"):
                break
            # lo start del nome persona tende a comparire dopo il blocco casa
            if candidate.lower().startswith("porzione di casa") or re.fullmatch(r"\(BOL\d+\)", candidate, re.I):
                property_parts.append(candidate)
                cursor += 1
                continue
            # se abbiamo già il blocco casa, il prossimo è verosimilmente il nome
            if property_parts:
                break
            property_parts.append(candidate)
            cursor += 1

        if not property_parts or cursor >= count:
            i += 1
            continue

        customer_name = clean(lines[cursor])
        cursor += 1

        people = "-"
        if cursor < count and is_people(lines[cursor]):
            people = clean(lines[cursor])
            cursor += 1

        phone = ""
        if cursor < count and is_phone(lines[cursor]) and not is_reference(lines[cursor]):
            phone = clean(lines[cursor])
            cursor += 1

        email_parts = []
        if cursor < count and "@" in lines[cursor]:
            email_parts.append(lines[cursor])
            cursor += 1
            while cursor < count:
                nxt = clean(lines[cursor])
                if not nxt or is_reference(nxt) or is_language(nxt) or is_date(nxt) or is_phone(nxt):
                    break
                if re.fullmatch(r"[A-Za-z0-9._%+\-]+", nxt):
                    email_parts.append(nxt)
                    cursor += 1
                    continue
                break

        customer_email = normalize_email(email_parts)

        external_reference = ""
        ref_pos = cursor
        while ref_pos < min(count, cursor + 6):
            cand = clean(lines[ref_pos])
            if is_reference(cand):
                external_reference = cand
                break
            if is_date(cand) or is_property_code(cand):
                break
            ref_pos += 1

        if not external_reference:
            i += 1
            continue

        cursor = ref_pos + 1
        language = ""
        if cursor < count and is_language(lines[cursor]):
            language = clean(lines[cursor])

        raw_property = clean(prop_code + " " + " ".join(property_parts))
        adults, children = parse_people(people)

        rows.append({
            "import_row_id": "",
            "stay_period": f"{check_in} - {check_out}",
            "check_in": check_in,
            "check_out": check_out,
            "room_type": map_room_type(raw_property),
            "customer_name": customer_name,
            "customer_email": customer_email,
            "customer_phone": phone,
            "adults": adults,
            "children_count": children,
            "notes": None,
            "status": "confermata",
            "source": "interhome_pdf",
            "external_reference": external_reference,
            "_language": language,
            "_raw_people": people,
            "_raw_property": raw_property,
            "_page": page_no,
            "_pdf_state": None,
        })

        i = ref_pos + 1

    return {"rows": rows, "notes": notes}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Percorso PDF mancante"}))
        sys.exit(1)

    pdf_path = sys.argv[1]

    doc = fitz.open(pdf_path)
    all_rows = []
    notes_by_ref = {}
    pages_read = 0

    for idx, page in enumerate(doc):
        lines = extract_page_lines(page)
        if not lines:
            continue
        pages_read += 1
        parsed = parse_page(idx + 1, lines)
        all_rows.extend(parsed["rows"])
        notes_by_ref.update(parsed["notes"])

    for row in all_rows:
        ref = row.get("external_reference")
        if ref and ref in notes_by_ref:
            row["notes"] = notes_by_ref[ref]

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