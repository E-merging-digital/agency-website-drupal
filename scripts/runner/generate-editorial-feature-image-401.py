#!/usr/bin/env python3
"""Generate the deterministic #401 governed editorial feature image.

The output is a palette-indexed PNG using only Python's standard library.
No external font, image, network, or platform-specific rasterizer is used.
"""

from __future__ import annotations

import argparse
import binascii
import struct
import zlib
from pathlib import Path

WIDTH = 1200
HEIGHT = 630

# DESIGN.md runtime tokens + two derived tints used only inside this illustration.
PALETTE = [
    (255, 255, 255),  # 0 --color-bg
    (246, 247, 251),  # 1 --color-surface
    (17, 24, 39),     # 2 --color-text
    (75, 85, 99),     # 3 --color-muted
    (209, 213, 219),  # 4 --color-border
    (0, 91, 187),     # 5 --color-primary
    (226, 239, 252),  # 6 primary tint
    (230, 232, 237),  # 7 soft shadow
]


def png_chunk(kind: bytes, payload: bytes) -> bytes:
    return (
        struct.pack(">I", len(payload))
        + kind
        + payload
        + struct.pack(">I", binascii.crc32(kind + payload) & 0xFFFFFFFF)
    )


def zlib_store(data: bytes) -> bytes:
    """Return a deterministic zlib stream made only of DEFLATE stored blocks."""
    out = bytearray(b"\x78\x01")
    offset = 0
    total = len(data)
    while offset < total:
        size = min(65535, total - offset)
        final = 1 if offset + size == total else 0
        out.append(final)
        out += struct.pack("<H", size)
        out += struct.pack("<H", 0xFFFF ^ size)
        out += data[offset:offset + size]
        offset += size
    out += struct.pack(">I", zlib.adler32(data) & 0xFFFFFFFF)
    return bytes(out)


def generate(output: Path) -> None:
    rows = [bytearray([0]) * WIDTH for _ in range(HEIGHT)]

    def px(x: int, y: int, color: int) -> None:
        if 0 <= x < WIDTH and 0 <= y < HEIGHT:
            rows[y][x] = color

    def rect(x0: int, y0: int, x1: int, y1: int, color: int) -> None:
        x0 = max(0, x0)
        y0 = max(0, y0)
        x1 = min(WIDTH, x1)
        y1 = min(HEIGHT, y1)
        fill = bytes([color]) * max(0, x1 - x0)
        for y in range(y0, y1):
            rows[y][x0:x1] = fill

    def circle(cx: int, cy: int, radius: int, color: int) -> None:
        r2 = radius * radius
        for y in range(cy - radius, cy + radius + 1):
            dy2 = (y - cy) * (y - cy)
            for x in range(cx - radius, cx + radius + 1):
                if (x - cx) * (x - cx) + dy2 <= r2:
                    px(x, y, color)

    def rounded_rect(x0: int, y0: int, x1: int, y1: int, radius: int, color: int) -> None:
        rect(x0 + radius, y0, x1 - radius, y1, color)
        rect(x0, y0 + radius, x1, y1 - radius, color)
        r2 = radius * radius
        corners = [
            (x0 + radius, y0 + radius),
            (x1 - radius - 1, y0 + radius),
            (x0 + radius, y1 - radius - 1),
            (x1 - radius - 1, y1 - radius - 1),
        ]
        for cx, cy in corners:
            for y in range(cy - radius, cy + radius + 1):
                for x in range(cx - radius, cx + radius + 1):
                    if (x - cx) ** 2 + (y - cy) ** 2 <= r2:
                        px(x, y, color)

    def line(x0: int, y0: int, x1: int, y1: int, width: int, color: int) -> None:
        dx = abs(x1 - x0)
        sx = 1 if x0 < x1 else -1
        dy = -abs(y1 - y0)
        sy = 1 if y0 < y1 else -1
        err = dx + dy
        half = width // 2
        while True:
            rect(x0 - half, y0 - half, x0 + half + 1, y0 + half + 1, color)
            if x0 == x1 and y0 == y1:
                break
            e2 = 2 * err
            if e2 >= dy:
                err += dy
                x0 += sx
            if e2 <= dx:
                err += dx
                y0 += sy

    def check(cx: int, cy: int) -> None:
        circle(cx, cy, 15, 6)
        circle(cx, cy, 10, 5)
        line(cx - 6, cy, cx - 1, cy + 6, 3, 0)
        line(cx - 1, cy + 6, cx + 8, cy - 7, 3, 0)

    rect(0, 0, WIDTH, HEIGHT, 0)
    rounded_rect(45, 45, 1155, 585, 30, 1)

    rounded_rect(78, 78, 210, 90, 6, 5)
    rounded_rect(990, 540, 1118, 552, 6, 5)

    rounded_rect(108, 112, 548, 518, 18, 7)
    rounded_rect(102, 106, 542, 512, 18, 0)
    rounded_rect(130, 133, 250, 143, 5, 5)
    rounded_rect(130, 158, 390, 166, 4, 4)

    row_y = [215, 270, 325, 380, 435]
    widths = [(210, 335), (185, 315), (230, 355), (198, 330), (218, 345)]
    for y, (w1, w2) in zip(row_y, widths):
        check(150, y)
        rounded_rect(184, y - 10, 184 + w1, y - 2, 4, 2)
        rounded_rect(184, y + 8, 184 + w2, y + 15, 3, 4)

    rounded_rect(616, 130, 1060, 445, 18, 7)
    rounded_rect(610, 124, 1054, 439, 18, 0)
    rect(610, 157, 1054, 159, 4)
    circle(636, 142, 5, 4)
    circle(654, 142, 5, 4)
    circle(672, 142, 5, 4)
    rounded_rect(700, 136, 925, 148, 6, 1)

    line(832, 213, 832, 250, 3, 4)
    line(706, 250, 958, 250, 3, 4)
    for cx in (706, 832, 958):
        line(cx, 250, cx, 284, 3, 4)

    rounded_rect(772, 185, 892, 224, 10, 5)
    for x0 in (655, 781, 907):
        rounded_rect(x0, 284, x0 + 102, 322, 10, 6)
        rounded_rect(x0 + 18, 298, x0 + 84, 306, 4, 5)

    line(706, 322, 706, 355, 3, 4)
    line(958, 322, 958, 355, 3, 4)
    rounded_rect(648, 355, 765, 394, 10, 1)
    rounded_rect(899, 355, 1016, 394, 10, 1)
    rounded_rect(666, 370, 747, 378, 4, 3)
    rounded_rect(917, 370, 998, 378, 4, 3)

    rounded_rect(592, 475, 1072, 487, 6, 4)
    rounded_rect(740, 488, 925, 500, 6, 7)

    raw = b"".join(b"\x00" + bytes(row) for row in rows)
    ihdr = struct.pack(">IIBBBBB", WIDTH, HEIGHT, 8, 3, 0, 0, 0)
    plte = b"".join(bytes(rgb) for rgb in PALETTE)
    png = (
        b"\x89PNG\r\n\x1a\n"
        + png_chunk(b"IHDR", ihdr)
        + png_chunk(b"PLTE", plte)
        + png_chunk(b"IDAT", zlib_store(raw))
        + png_chunk(b"IEND", b"")
    )
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_bytes(png)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    generate(args.output)


if __name__ == "__main__":
    main()
