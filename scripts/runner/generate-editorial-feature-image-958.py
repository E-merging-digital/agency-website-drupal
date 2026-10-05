#!/usr/bin/env python3
"""Generate the deterministic #958 Drupal 10 -> 11 upgrade planning image."""

from __future__ import annotations

import argparse
import binascii
import struct
import zlib
from pathlib import Path

WIDTH = 1200
HEIGHT = 630

PALETTE = [
    (255, 255, 255),
    (246, 247, 251),
    (17, 24, 39),
    (75, 85, 99),
    (209, 213, 219),
    (0, 91, 187),
    (226, 239, 252),
    (230, 232, 237),
    (222, 247, 236),
    (26, 127, 86),
]


def png_chunk(kind: bytes, payload: bytes) -> bytes:
    return (
        struct.pack(">I", len(payload))
        + kind
        + payload
        + struct.pack(">I", binascii.crc32(kind + payload) & 0xFFFFFFFF)
    )


def zlib_store(data: bytes) -> bytes:
    """Return a deterministic zlib stream made only of stored blocks."""
    out = bytearray(b"\x78\x01")
    offset = 0
    while offset < len(data):
        size = min(65535, len(data) - offset)
        final = 1 if offset + size == len(data) else 0
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
        radius_squared = radius * radius
        for y in range(cy - radius, cy + radius + 1):
            dy_squared = (y - cy) * (y - cy)
            for x in range(cx - radius, cx + radius + 1):
                if (x - cx) * (x - cx) + dy_squared <= radius_squared:
                    px(x, y, color)

    def rounded_rect(x0: int, y0: int, x1: int, y1: int, radius: int, color: int) -> None:
        rect(x0 + radius, y0, x1 - radius, y1, color)
        rect(x0, y0 + radius, x1, y1 - radius, color)
        radius_squared = radius * radius
        corners = [
            (x0 + radius, y0 + radius),
            (x1 - radius - 1, y0 + radius),
            (x0 + radius, y1 - radius - 1),
            (x1 - radius - 1, y1 - radius - 1),
        ]
        for cx, cy in corners:
            for y in range(cy - radius, cy + radius + 1):
                for x in range(cx - radius, cx + radius + 1):
                    if (x - cx) ** 2 + (y - cy) ** 2 <= radius_squared:
                        px(x, y, color)

    def line(x0: int, y0: int, x1: int, y1: int, width: int, color: int) -> None:
        dx = abs(x1 - x0)
        sx = 1 if x0 < x1 else -1
        dy = -abs(y1 - y0)
        sy = 1 if y0 < y1 else -1
        error = dx + dy
        half = width // 2
        while True:
            rect(x0 - half, y0 - half, x0 + half + 1, y0 + half + 1, color)
            if x0 == x1 and y0 == y1:
                break
            doubled = 2 * error
            if doubled >= dy:
                error += dy
                x0 += sx
            if doubled <= dx:
                error += dx
                y0 += sy

    def check(cx: int, cy: int) -> None:
        circle(cx, cy, 14, 8)
        circle(cx, cy, 9, 9)
        line(cx - 5, cy, cx - 1, cy + 5, 3, 0)
        line(cx - 1, cy + 5, cx + 7, cy - 6, 3, 0)

    def digit_one(x: int, y: int, scale: int, color: int) -> None:
        rect(x + 4 * scale, y, x + 7 * scale, y + 18 * scale, color)
        rect(x + scale, y + 3 * scale, x + 4 * scale, y + 6 * scale, color)
        rect(x + scale, y + 15 * scale, x + 10 * scale, y + 18 * scale, color)

    def digit_zero(x: int, y: int, scale: int, color: int) -> None:
        rect(x, y, x + 3 * scale, y + 18 * scale, color)
        rect(x + 9 * scale, y, x + 12 * scale, y + 18 * scale, color)
        rect(x, y, x + 12 * scale, y + 3 * scale, color)
        rect(x, y + 15 * scale, x + 12 * scale, y + 18 * scale, color)

    def number_10(x: int, y: int, scale: int, color: int) -> None:
        digit_one(x, y, scale, color)
        digit_zero(x + 14 * scale, y, scale, color)

    def number_11(x: int, y: int, scale: int, color: int) -> None:
        digit_one(x, y, scale, color)
        digit_one(x + 12 * scale, y, scale, color)

    rect(0, 0, WIDTH, HEIGHT, 0)
    rounded_rect(44, 44, 1156, 586, 30, 1)
    rounded_rect(78, 78, 214, 90, 6, 5)
    rounded_rect(986, 540, 1122, 552, 6, 5)

    rounded_rect(95, 135, 355, 405, 22, 7)
    rounded_rect(89, 129, 349, 399, 22, 0)
    rounded_rect(851, 135, 1111, 405, 22, 7)
    rounded_rect(845, 129, 1105, 399, 22, 0)
    rounded_rect(122, 158, 316, 170, 6, 4)
    rounded_rect(878, 158, 1072, 170, 6, 5)

    circle(219, 270, 74, 6)
    number_10(160, 225, 5, 5)
    circle(975, 270, 74, 6)
    number_11(921, 225, 5, 5)
    rounded_rect(142, 362, 295, 372, 5, 4)
    rounded_rect(898, 362, 1051, 372, 5, 4)

    line(350, 270, 845, 270, 8, 4)
    steps = [420, 520, 620, 720, 805]
    for index, x in enumerate(steps):
        circle(x, 270, 23, 6 if index < 4 else 8)
        circle(x, 270, 12, 5 if index < 4 else 9)
        if index < 4:
            check(x, 270)
    line(382, 270, 402, 270, 5, 5)
    line(828, 270, 842, 270, 5, 9)

    for index, x in enumerate([405, 505, 605, 705]):
        rounded_rect(x, 166, x + 72, 205, 10, 0)
        rounded_rect(x + 12, 181, x + 60, 188, 4, 5 if index < 3 else 9)

    rounded_rect(360, 340, 840, 510, 18, 0)
    rounded_rect(382, 362, 520, 374, 6, 5)
    row_specs = [
        (405, 420, 640),
        (405, 455, 700),
        (610, 420, 805),
        (610, 455, 785),
    ]
    for cx, cy, end_x in row_specs:
        check(cx, cy)
        rounded_rect(cx + 28, cy - 6, end_x, cy + 1, 3, 2)
        rounded_rect(cx + 28, cy + 8, end_x - 35, cy + 14, 3, 4)

    line(789, 383, 815, 395, 4, 9)
    line(815, 395, 835, 371, 4, 9)

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
