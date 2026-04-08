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
    return s.startswith("+")


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


def extract_page_lines(page) -> List[str]:
    text = page.get_text("text")
    raw_lines = [clean(x) for x in text.splitlines()]
    lines = []

    for line in raw_lines:
        if not line:
            continue

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

        lines.append(line)

    return lines


def parse_page(page_no: int, lines: List[str]) -> Dict[str, Any]:
    rows = []
    pending_note = None
    i = 0
    count = len(lines)

    while i < count:
        line = lines[i]

        if line.lower().startswith("note:"):
            pending_note = line
            i += 1
            continue

        if i + 2 >= count:
            break

        if not is_date(lines[i]) or not is_date(lines[i + 1]) or not is_property_code(lines[i + 2]):
            i += 1
            continue

        check_in = lines[i]
        check_out = lines[i + 1]
        prop_code = lines[i + 2]
        i += 3

        property_parts = []
        while i < count:
            cur = lines[i]
            if cur.lower().startswith("porzione di casa") or re.fullmatch(r"\(BOL\d+\)", cur, re.I):
                property_parts.append(cur)
                i += 1
                continue
            break

        if i >= count:
            break

        customer_name = lines[i]
        i += 1

        people = "-"
        if i < count and is_people(lines[i]):
            people = lines[i]
            i += 1

        phone = ""
        if i < count and is_phone(lines[i]):
            phone = lines[i]
            i += 1

                email = ""
        if i < count and "@" in lines[i]:
            email_parts = [lines[i]]
            i += 1

            while i < count:
                nxt = lines[i]

                if is_reference(nxt) or is_language(nxt) or is_date(nxt) or is_property_code(nxt):
                    break

                # frammenti tipici di email spezzata, es. "m"
                if re.fullmatch(r"[A-Za-z0-9._%+\-]+", nxt):
                    email_parts.append(nxt)
                    i += 1
                    continue

                # evita di mangiare note o testo libero
                if nxt.lower().startswith("note:"):
                    break

                break

            email = "".join(email_parts).strip()

        if i >= count or not is_reference(lines[i]):
            continue

        external_reference = lines[i]
        i += 1

        language = ""
        if i < count and is_language(lines[i]):
            language = lines[i]
            i += 1

        extra_notes = []
        while i < count:
            nxt = lines[i]
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
            i += 1

        raw_property = clean(prop_code + " " + " ".join(property_parts))
        adults, children = parse_people(people)

        notes = []
        if pending_note:
            notes.append(pending_note)
            pending_note = None
        notes.extend(extra_notes)

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
            "_raw_people": people,
            "_raw_property": raw_property,
            "_page": page_no,
            "_pdf_state": None,
        })

    return {"rows": rows}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Percorso PDF mancante"}))
        sys.exit(1)

    pdf_path = sys.argv[1]
    doc = fitz.open(pdf_path)

    all_rows = []
    pages_read = 0

    for idx, page in enumerate(doc):
        lines = extract_page_lines(page)
        if not lines:
            continue
        pages_read += 1
        parsed = parse_page(idx + 1, lines)
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