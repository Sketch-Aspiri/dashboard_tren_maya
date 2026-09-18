#!/usr/bin/env python3
"""
Builds resources/documentos/asistencia_zona.docx from the real "Control de
asistencia diaria" Word of the Dirección (the example the Jefe de Zona
supplied), turning it into a PhpWord TemplateProcessor template.

What it does to the original:
  * puts ${placeholders} where the data goes (single <w:t> in a single run);
  * keeps ONE template row per list table (cloneRow clones it) and the
    summary tables' single data row;
  * removes the scanned annexes (image2-4), the scanned signature (image1 +
    hdphoto1), and replaces the floating "suplencia" text box (plus its VML
    duplicate) with one normal paragraph holding ${texto_suplencia};
  * collapses long runs of empty spacer paragraphs (they only existed to
    push the fixed-size original onto pages) and drops orphan media/rels.

The letterhead (image5 in the header) and footer (image6), styles, fonts,
the 3-level header of the "comisionados" table and every tblHeader are kept.

Usage (from the project root; needs `pip install lxml`):

    python resources/documentos/build/build_plantilla_zona.py [--source ORIGINAL.docx]

--source defaults to the ZO_*.docx in
storage/app/private/imports/documentos-autogenerados/.

Placeholder contract (must match App\\Services\\Documentos\\GeneradorOficioZona):
  scalars : numero_oficio fecha_corta texto_suplencia firmante_cargo
            firmante_nombre iniciales
  summary : mil_* per_* ev_*  (descanso dia_no_laborable licencia_medica
            comision aviso_incidencia reporte_ausentismo vacaciones bajas
            nuevos_ingresos presente total)
            com_* (descanso dia_no_laborable licencia_medica
            comision_permanente comision_eventual comision_otra
            aviso_incidencia reporte_ausentismo vacaciones bajas
            nuevos_ingresos presente total)
  lists   : m p e otra fuera fueraev, each with _no _dir _trab _nombre
            _estatus _ubic
"""

import argparse
import copy
import glob
import re
import sys
import zipfile
from pathlib import Path

from lxml import etree

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
XML = "http://www.w3.org/XML/1998/namespace"
NS = {"w": W}

PROJECT_ROOT = Path(__file__).resolve().parents[3]
DEFAULT_SOURCE_GLOB = str(PROJECT_ROOT / "storage/app/private/imports/documentos-autogenerados/ZO_*.docx")
OUTPUT = PROJECT_ROOT / "resources/documentos/asistencia_zona.docx"

# Parts/relationships that only the signature and the annexes used.
DROP_MEDIA = {
    "word/media/image1.png",   # scanned signature
    "word/media/hdphoto1.wdp",  # signature's photocopy-effect layer
    "word/media/image2.png",   # annex: reporte de ausentismo
    "word/media/image3.png",   # annex: oficio de comisión (p.1)
    "word/media/image4.png",   # annex: oficio de comisión (p.2)
}
DROP_REL_IDS = {"rId8", "rId9", "rId10", "rId11", "rId12"}

SUMMARY_SUFFIXES = [
    "descanso", "dia_no_laborable", "licencia_medica", "comision", "aviso_incidencia",
    "reporte_ausentismo", "vacaciones", "bajas", "nuevos_ingresos", "presente", "total",
]
COMISIONADOS_SUFFIXES = [
    "descanso", "dia_no_laborable", "licencia_medica", "comision_permanente", "comision_eventual",
    "comision_otra", "aviso_incidencia", "reporte_ausentismo", "vacaciones", "bajas",
    "nuevos_ingresos", "presente", "total",
]
LIST_SUFFIXES = ["no", "dir", "trab", "nombre", "estatus", "ubic"]


def q(tag: str) -> str:
    return f"{{{W}}}{tag}"


# --------------------------------------------------------------------------
# Text helpers
# --------------------------------------------------------------------------

def paragraph_text(p) -> str:
    return "".join(t.text or "" for t in p.iter(q("t")))


def replace_in_paragraph(p, old: str, new: str) -> None:
    """Replaces `old` (which may span several runs) with `new`, leaving the
    result inside the first <w:t> that held part of `old`."""
    ts = list(p.iter(q("t")))
    full = "".join(t.text or "" for t in ts)
    start = full.find(old)
    if start < 0:
        raise SystemExit(f"No se encontró {old!r} en el párrafo: {full[:80]!r}")
    end = start + len(old)

    pos = 0
    placed = False
    for t in ts:
        text = t.text or ""
        t_start, t_end = pos, pos + len(text)
        pos = t_end
        if t_end <= start or t_start >= end:
            continue
        prefix = text[: max(start - t_start, 0)]
        suffix = text[max(end - t_start, 0):] if t_end > end else ""
        if not placed:
            t.text = prefix + new + suffix
            placed = True
        else:
            t.text = suffix
        t.set(f"{{{XML}}}space", "preserve")


def set_cell_text(tc, text: str) -> None:
    """Leaves exactly one paragraph with one run holding `text`, keeping the
    formatting of the first run that had text (or the paragraph mark's)."""
    paragraphs = tc.findall(q("p"))
    first = paragraphs[0]
    for extra in paragraphs[1:]:
        tc.remove(extra)

    base_run = next((r for r in first.findall(q("r")) if r.find(q("t")) is not None), None)
    if base_run is not None:
        rpr = base_run.find(q("rPr"))
        rpr = copy.deepcopy(rpr) if rpr is not None else None
    else:
        mark = first.find(f"{q('pPr')}/{q('rPr')}")
        rpr = copy.deepcopy(mark) if mark is not None else None

    for child in list(first):
        if child.tag != q("pPr"):
            first.remove(child)

    run = etree.SubElement(first, q("r"))
    if rpr is not None:
        run.append(rpr)
    t = etree.SubElement(run, q("t"))
    t.text = text
    t.set(f"{{{XML}}}space", "preserve")


# --------------------------------------------------------------------------
# Transformations
# --------------------------------------------------------------------------

def fill_summary_table(tbl, prefix: str, suffixes: list[str]) -> None:
    data_row = tbl.findall(q("tr"))[-1]
    cells = data_row.findall(q("tc"))
    if len(cells) != len(suffixes):
        raise SystemExit(f"Tabla resumen {prefix}: {len(cells)} celdas, se esperaban {len(suffixes)}")
    for tc, suffix in zip(cells, suffixes):
        set_cell_text(tc, "${%s%s}" % (prefix, suffix))


def fill_list_table(tbl, prefix: str) -> None:
    rows = tbl.findall(q("tr"))
    # The original's first data row is highlighted (bold); the second one has
    # the regular formatting every other row shares, so it is the better
    # template. Tables with a single (empty) data row only have row 1.
    template_row = rows[2] if len(rows) > 2 else rows[1]
    for extra in rows[1:]:
        if extra is not template_row:
            tbl.remove(extra)
    cells = template_row.findall(q("tc"))
    if len(cells) != len(LIST_SUFFIXES):
        raise SystemExit(f"Tabla lista {prefix}: {len(cells)} celdas, se esperaban {len(LIST_SUFFIXES)}")
    for tc, suffix in zip(cells, LIST_SUFFIXES):
        set_cell_text(tc, "${%s_%s}" % (prefix, suffix))
    forbid_row_split(template_row)


# Elements that must come AFTER <w:cantSplit/> inside <w:trPr> (schema order).
_AFTER_CANT_SPLIT = ("trHeight", "tblHeader", "tblCellSpacing", "jc", "hidden", "ins", "del", "trPrChange")


def forbid_row_split(row) -> None:
    """Keeps a generated row on one page (a name/status must never be cut in half)."""
    tr_pr = row.find(q("trPr"))
    if tr_pr is None:
        tr_pr = etree.Element(q("trPr"))
        tbl_pr_ex = row.find(q("tblPrEx"))
        row.insert(0 if tbl_pr_ex is None else row.index(tbl_pr_ex) + 1, tr_pr)
    if tr_pr.find(q("cantSplit")) is not None:
        return
    marker = etree.Element(q("cantSplit"))
    for index, child in enumerate(tr_pr):
        if etree.QName(child).localname in _AFTER_CANT_SPLIT:
            tr_pr.insert(index, marker)
            return
    tr_pr.append(marker)


def is_blank_paragraph(el) -> bool:
    return (
        el.tag == q("p")
        and not paragraph_text(el).strip()
        and el.find(f".//{q('drawing')}") is None
        and el.find(f".//{q('pict')}") is None
        and el.find(f".//{q('sectPr')}") is None
    )


def collapse_blank_runs(body, max_run: int = 2) -> None:
    """Runs of more than `max_run` empty paragraphs shrink to one."""
    run: list = []

    def flush():
        if len(run) > max_run:
            for extra in run[1:]:
                body.remove(extra)
        run.clear()

    for el in list(body):
        if is_blank_paragraph(el):
            run.append(el)
        else:
            flush()
    flush()


def replace_suplencia_box(p) -> None:
    """The floating text box (DrawingML + VML fallback) becomes a plain
    justified paragraph, so it flows with the content and disappears
    cleanly when there is no suplencia."""
    for child in list(p):
        p.remove(child)

    ppr = etree.SubElement(p, q("pPr"))
    etree.SubElement(ppr, q("widowControl")).set(q("val"), "0")
    etree.SubElement(ppr, q("autoSpaceDE")).set(q("val"), "0")
    etree.SubElement(ppr, q("autoSpaceDN")).set(q("val"), "0")
    spacing = etree.SubElement(ppr, q("spacing"))
    spacing.set(q("after"), "0")
    etree.SubElement(ppr, q("jc")).set(q("val"), "both")

    run = etree.SubElement(p, q("r"))
    rpr = etree.SubElement(run, q("rPr"))
    fonts = etree.SubElement(rpr, q("rFonts"))
    for attr in ("ascii", "hAnsi", "cs"):
        fonts.set(q(attr), "Noto Sans")
    fonts.set(q("eastAsia"), "Times New Roman")
    etree.SubElement(rpr, q("sz")).set(q("val"), "18")
    etree.SubElement(rpr, q("szCs")).set(q("val"), "18")
    t = etree.SubElement(run, q("t"))
    t.text = "${texto_suplencia}"


def keep_with_next(p, keep_lines: bool = False) -> None:
    """Adds <w:keepNext/> (and optionally <w:keepLines/>) at its schema
    position: right after <w:pStyle>, before every other pPr child."""
    ppr = p.find(q("pPr"))
    if ppr is None:
        ppr = etree.Element(q("pPr"))
        p.insert(0, ppr)
    at = 1 if ppr.find(q("pStyle")) is not None else 0
    for tag in (["keepLines"] if keep_lines else []) + ["keepNext"]:
        if ppr.find(q(tag)) is None:
            ppr.insert(at, etree.Element(q(tag)))


def keep_closing_block_together(suplencia_p, firmante_nombre_p) -> None:
    """suplencia -> Respetuosamente -> cargo -> firma space -> name stay on
    one page, so the signature is never orphaned on a page of its own."""
    keep_lines_done = False
    el = suplencia_p
    while el is not None and el is not firmante_nombre_p:
        if el.tag == q("p"):
            keep_with_next(el, keep_lines=not keep_lines_done)
            keep_lines_done = True
        el = el.getnext()


def remove_signature_drawing(p) -> None:
    for run in p.findall(q("r")):
        if run.find(f".//{q('drawing')}") is not None:
            p.remove(run)


def transform_document(xml: bytes) -> bytes:
    root = etree.fromstring(xml)
    body = root.find(q("body"))
    children = list(body)

    # Locate elements by content, not by position, so a slightly different
    # original still works (and a wrong one fails loudly).
    def find_p(startswith: str):
        for el in children:
            if el.tag == q("p") and paragraph_text(el).strip().startswith(startswith):
                return el
        raise SystemExit(f"No se encontró el párrafo que empieza con {startswith!r}")

    tables = [el for el in children if el.tag == q("tbl")]
    if len(tables) != 10:
        raise SystemExit(f"Se esperaban 10 tablas y hay {len(tables)}")
    mil, per, ev, com, lst_m, lst_p, lst_e, lst_otra, lst_fuera, lst_fueraev = tables

    # -- scalars ---------------------------------------------------------
    replace_in_paragraph(find_p("Tjta. No."), "TM/UAI/CGGIF/DGTZO/1735", "${numero_oficio}")
    date_p = next(el for el in children if el.tag == q("p") and paragraph_text(el).strip() == "18 Sep. 2026")
    replace_in_paragraph(date_p, "18 Sep. 2026", "${fecha_corta}")
    replace_in_paragraph(find_p("En relación al Memorándum"), "18 Sep. 2026", "${fecha_corta}")
    replace_in_paragraph(find_p("Subgerencia de Operación"), "Subgerencia de Operación y Enlace con estaciones Zona Oriente", "${firmante_cargo}")
    replace_in_paragraph(find_p("Mtro."), "Mtro. Jesús Alberto Tec Pimentel", "${firmante_nombre}")
    replace_in_paragraph(find_p("JATP-"), "JATP-narv", "${iniciales}")

    # -- summary tables --------------------------------------------------
    fill_summary_table(mil, "mil_", SUMMARY_SUFFIXES)
    fill_summary_table(per, "per_", SUMMARY_SUFFIXES)
    fill_summary_table(ev, "ev_", SUMMARY_SUFFIXES)
    fill_summary_table(com, "com_", COMISIONADOS_SUFFIXES)

    # -- list tables -----------------------------------------------------
    for tbl, prefix in (
        (lst_m, "m"), (lst_p, "p"), (lst_e, "e"),
        (lst_otra, "otra"), (lst_fuera, "fuera"), (lst_fueraev, "fueraev"),
    ):
        fill_list_table(tbl, prefix)

    # -- suplencia, signature, annexes ------------------------------------
    suplencia_p = next(
        el for el in children
        if el.tag == q("p") and el.find(f".//{q('drawing')}") is not None
        and "El presente documento lo firmo" in paragraph_text(el)
    )
    replace_suplencia_box(suplencia_p)

    respetuosamente = next(
        el for el in children if el.tag == q("p") and "Respetuosamente" in paragraph_text(el)
    )
    remove_signature_drawing(respetuosamente)

    iniciales_p = find_p("${iniciales}")
    seen = False
    for el in children:
        if el is iniciales_p:
            seen = True
            continue
        # Everything after the initials except the section properties is annex padding.
        if seen and el.tag != q("sectPr"):
            body.remove(el)

    # Without the scanned signature the gap between the signer's position and
    # name is only two blank lines: give it room for a real one.
    cargo_p = find_p("${firmante_cargo}")
    following = cargo_p.getnext()
    for _ in range(2):
        following.addnext(copy.deepcopy(following))

    for el in root.iter(q("lastRenderedPageBreak")):
        el.getparent().remove(el)

    collapse_blank_runs(body)
    keep_closing_block_together(suplencia_p, find_p("${firmante_nombre}"))

    return etree.tostring(root, xml_declaration=True, encoding="UTF-8", standalone=True)


def transform_rels(xml: bytes) -> bytes:
    root = etree.fromstring(xml)
    for rel in list(root):
        if rel.get("Id") in DROP_REL_IDS:
            root.remove(rel)
    return etree.tostring(root, xml_declaration=True, encoding="UTF-8", standalone=True)


def transform_content_types(xml: bytes) -> bytes:
    # The .wdp default only served the signature's photocopy layer.
    return re.sub(rb'<Default Extension="wdp"[^>]*/>', b"", xml)


def build(source: Path, output: Path) -> None:
    with zipfile.ZipFile(source) as zin:
        parts = {info.filename: zin.read(info.filename) for info in zin.infolist()}
        order = [info.filename for info in zin.infolist()]

    parts["word/document.xml"] = transform_document(parts["word/document.xml"])
    parts["word/_rels/document.xml.rels"] = transform_rels(parts["word/_rels/document.xml.rels"])
    parts["[Content_Types].xml"] = transform_content_types(parts["[Content_Types].xml"])

    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as zout:
        for name in order:
            if name in DROP_MEDIA:
                continue
            zout.writestr(name, parts[name])

    print(f"Plantilla escrita en {output}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--source", help="Word original del oficio de zona (.docx)")
    args = parser.parse_args()

    source = args.source or next(iter(glob.glob(DEFAULT_SOURCE_GLOB)), None)
    if not source or not Path(source).is_file():
        sys.exit("No se encontró el .docx original; indícalo con --source.")

    build(Path(source), OUTPUT)


if __name__ == "__main__":
    main()
