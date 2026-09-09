#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Parser del calendario de formación del SENA (formato "PROGRAMADOR SESIONES").

Layout del Excel/Google Sheet:
  - Los MESES van en horizontal, en bloques de 7 columnas:
        offset 0 = columna "HORARIO"
        offset 1..6 = Lunes..Sabado
  - Las SEMANAS van en vertical: una fila de FECHAS (encabezado de semana)
    seguida de hasta 8 filas de franjas horarias ("12:00 - 13:00", ...).
  - Cada celda de asignación = "INSTRUCTOR - COMPETENCIA".
  - Valores especiales: "FESTIVO" (se ignora), celdas vacías (se ignoran).

Salida: lista de ocupaciones ya AGRUPADAS (horas contiguas del mismo
instructor+competencia el mismo día se unen en un solo bloque).
"""

import re
import sys
import json
import datetime as dt

TIME_RE = re.compile(r'^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$')
IGNORAR = {'', 'FESTIVO', 'HORARIO', 'NO HAY FORMACION', 'NO HAY FORMACIÓN',
           'PROGRAMADOR SESIONES FORMATIVAS', 'PROGRAMADOR SESION'}


def _norm(s):
    return re.sub(r'\s+', ' ', str(s)).strip()


def _es_fecha(v):
    return isinstance(v, (dt.datetime, dt.date))


def _a_date(v):
    return v.date() if isinstance(v, dt.datetime) else v


def parse_worksheet(ws):
    """Recibe una hoja openpyxl (data_only) y devuelve (asignaciones, meta)."""
    max_row = ws.max_row
    max_col = ws.max_column

    # Metadatos (ficha / programa) — se buscan en las primeras filas.
    ficha = programa = None
    for r in range(1, 8):
        for c in range(1, max_col + 1):
            val = ws.cell(row=r, column=c).value
            if not isinstance(val, str):
                continue
            t = val.strip().lower()
            if t.startswith('ficha'):
                nxt = ws.cell(row=r, column=c + 1).value
                if nxt:
                    ficha = _norm(nxt)
            elif t.startswith('programa'):
                nxt = ws.cell(row=r, column=c + 1).value
                if nxt:
                    programa = _norm(nxt)

    # Detectar el inicio de cada bloque de mes: columnas cuyo encabezado dice HORARIO.
    # Un bloque ocupa 7 columnas (HORARIO + 6 días).
    bloques = []
    c = 1
    while c <= max_col:
        # ¿esta columna es una columna HORARIO? (aparece "HORARIO" en varias filas)
        es_horario = False
        for r in range(5, min(max_row, 40) + 1):
            v = ws.cell(row=r, column=c).value
            if isinstance(v, str) and v.strip().upper() == 'HORARIO':
                es_horario = True
                break
        if es_horario:
            bloques.append(c)
            c += 7
        else:
            c += 1

    asignaciones = []  # dicts: fecha, hi, hf, instructor, competencia
    otros = []         # celdas con texto que NO son clases (ALISTAMIENTO, notas)

    for c0 in bloques:
        fechas_col = {j: None for j in range(1, 7)}  # día -> fecha vigente
        for r in range(1, max_row + 1):
            etiqueta = ws.cell(row=r, column=c0).value

            # ¿Fila de encabezado de semana? -> alguna celda de día es fecha.
            hay_fecha = False
            posibles = {}
            for j in range(1, 7):
                v = ws.cell(row=r, column=c0 + j).value
                if _es_fecha(v):
                    posibles[j] = _a_date(v)
                    hay_fecha = True
            if hay_fecha:
                for j in range(1, 7):
                    fechas_col[j] = posibles.get(j)  # None si esa celda no traía fecha
                continue

            # ¿Fila de franja horaria?
            m = TIME_RE.match(str(etiqueta)) if etiqueta is not None else None
            if not m:
                continue
            hi = f"{int(m.group(1)):02d}:{m.group(2)}"
            hf = f"{int(m.group(3)):02d}:{m.group(4)}"

            for j in range(1, 7):
                fecha = fechas_col[j]
                if not fecha:
                    continue
                val = ws.cell(row=r, column=c0 + j).value
                if not isinstance(val, str):
                    continue
                texto = _norm(val)
                if texto.upper() in {x.upper() for x in IGNORAR}:
                    continue
                # Una clase real SIEMPRE tiene el formato "INSTRUCTOR - COMPETENCIA".
                # Lo demas (ALISTAMIENTO, notas del calendario) se registra aparte.
                if ' - ' not in texto:
                    otros.append({'fecha': fecha.isoformat(), 'hi': hi, 'hf': hf,
                                  'texto': texto})
                    continue
                instructor, competencia = texto.split(' - ', 1)
                instructor = _norm(instructor)
                competencia = _norm(competencia)
                if instructor.upper() in {x.upper() for x in IGNORAR}:
                    continue
                asignaciones.append({
                    'fecha': fecha.isoformat(),
                    'hi': hi, 'hf': hf,
                    'instructor': instructor,
                    'competencia': competencia,
                })

    meta = {'ficha': ficha, 'programa': programa,
            'bloques_mes': len(bloques),
            'asignaciones_hora': len(asignaciones),
            'otros_no_clase': len(otros)}
    return asignaciones, meta, otros


def agrupar(asignaciones):
    """Une franjas horarias contiguas del mismo día+instructor+competencia."""
    # Ordenar por fecha, instructor, competencia, hora inicio
    def key(a):
        return (a['fecha'], a['instructor'], a['competencia'], a['hi'])
    asg = sorted(asignaciones, key=key)

    ocupaciones = []
    actual = None
    for a in asg:
        if (actual and a['fecha'] == actual['fecha']
                and a['instructor'] == actual['instructor']
                and a['competencia'] == actual['competencia']
                and a['hi'] == actual['hf']):          # contigua
            actual['hf'] = a['hf']
        else:
            if actual:
                ocupaciones.append(actual)
            actual = dict(a)
    if actual:
        ocupaciones.append(actual)
    return ocupaciones


def main():
    import openpyxl
    ruta = sys.argv[1]
    wb = openpyxl.load_workbook(ruta, data_only=True)
    ws = wb.active
    asg, meta, otros = parse_worksheet(ws)
    ocup = agrupar(asg)
    print(json.dumps({'meta': meta, 'ocupaciones': ocup, 'otros': otros},
                     ensure_ascii=False))


if __name__ == '__main__':
    main()
