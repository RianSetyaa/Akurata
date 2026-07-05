#!/usr/bin/env python3
from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "guidebook" / "screenshots"
CHROME = Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
BASE_URL = "http://127.0.0.1:8020"


SHOTS = [
    ("00-login", f"{BASE_URL}/login.html", "1440,900"),
    ("dashboard", f"{BASE_URL}/guidebook/capture.html?screen=dashboard", "1440,900"),
    ("sales", f"{BASE_URL}/guidebook/capture.html?screen=sales", "1440,900"),
    ("products", f"{BASE_URL}/guidebook/capture.html?screen=products", "1440,900"),
    ("purchases", f"{BASE_URL}/guidebook/capture.html?screen=purchases", "1440,900"),
    ("receivables", f"{BASE_URL}/guidebook/capture.html?screen=receivables", "1440,900"),
    ("reports", f"{BASE_URL}/guidebook/capture.html?screen=reports", "1440,900"),
    ("settings", f"{BASE_URL}/guidebook/capture.html?screen=settings", "1440,900"),
    ("integrations", f"{BASE_URL}/guidebook/capture.html?screen=integrations", "1440,900"),
    ("access", f"{BASE_URL}/guidebook/capture.html?screen=access", "1440,900"),
    ("admin", f"{BASE_URL}/guidebook/capture.html?screen=admin", "1440,900"),
    ("mobile-products", f"{BASE_URL}/guidebook/capture.html?screen=products", "390,844"),
]


def run_shot(name: str, url: str, window_size: str) -> None:
    profile = Path(tempfile.mkdtemp(prefix=f"akurata-guide-{name}-"))
    target = OUT / f"{name}.png"
    command = [
        str(CHROME),
        "--headless=new",
        "--no-sandbox",
        "--disable-gpu",
        "--hide-scrollbars",
        "--disable-background-networking",
        "--disable-component-update",
        "--disable-sync",
        "--metrics-recording-only",
        "--disable-extensions",
        "--disable-default-apps",
        "--disable-features=OptimizationGuideModelDownloading,AutofillServerCommunication",
        "--run-all-compositor-stages-before-draw",
        "--virtual-time-budget=2500",
        f"--user-data-dir={profile}",
        f"--window-size={window_size}",
        f"--screenshot={target}",
        url,
    ]

    try:
        subprocess.run(command, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=25)
    except subprocess.TimeoutExpired:
        # Chrome sometimes keeps updater helpers alive after the screenshot is written.
        pass
    finally:
        shutil.rmtree(profile, ignore_errors=True)

    if not target.exists() or target.stat().st_size < 10_000:
        raise RuntimeError(f"Screenshot gagal dibuat: {name}")
    print(f"{name}: {target.stat().st_size // 1024} KB")


def main() -> int:
    if not CHROME.exists():
        print(f"Chrome tidak ditemukan: {CHROME}", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    for name, url, window_size in SHOTS:
      run_shot(name, url, window_size)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
