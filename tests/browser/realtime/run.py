"""Run the Chrome journey against disposable siblings. Pass --native on a Mac with the Android/iOS build tools."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

here = Path(__file__).resolve().parent
root = here.parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--native", action="store_true")
args = parser.parse_args()


def stop(proc):
    if proc is not None and proc.poll() is None:
        proc.terminate()
        try:
            proc.wait(timeout=12)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait()


with tempfile.TemporaryDirectory(prefix="xpn-realtime-") as scratch:
    control = Path(scratch)
    web = control / "web"
    web.mkdir()
    frontend = root.parent / "xspann-frontend"
    for name in ("src", "public"):
        shutil.copytree(frontend / name, web / name)
    for name in ("package.json", "next.config.ts", "tsconfig.json", "postcss.config.mjs", "AGENTS.md"):
        shutil.copy(frontend / name, web / name)
    (web / "node_modules").symlink_to(frontend / "node_modules", target_is_directory=True)
    server = subprocess.Popen(["php", str(here / "server.php"), scratch], cwd=root)
    next_server = peer = None
    try:
        for _ in range(200):
            if (control / "ready.json").is_file():
                break
            if server.poll() is not None:
                raise RuntimeError("Fixture server exited")
            time.sleep(0.1)
        settings = json.loads((control / "ready.json").read_text())
        env = dict(os.environ, NEXT_PUBLIC_API_URL="/api/v1", API_BACKEND_URL=settings["api"], NEXT_TELEMETRY_DISABLED="1")
        next_server = subprocess.Popen(["node", "node_modules/next/dist/bin/next", "dev", "--webpack",
                                        "-H", "127.0.0.1", "-p", settings["web"].rsplit(":", 1)[1]], cwd=web, env=env)
        import urllib.error
        import urllib.request
        for _ in range(120):
            try:
                urllib.request.urlopen(settings["web"] + "/api/v1/auth/me", timeout=2).close()
            except urllib.error.HTTPError as error:
                if error.code == 401:
                    break
            except OSError:
                pass
            time.sleep(0.25)
        subprocess.run(["node", str(here / "chat.cjs"), scratch], cwd=root, check=True)
        if args.native:
            native = root.parent / "xpn-native"
            config = control / "kotlin"
            config.mkdir()
            (config / "JourneyConfig.kt").write_text(
                "package com.xpn.social.presentation\ninternal const val journeyApi = " + json.dumps(settings["api"]) +
                "\ninternal const val journeyWeb = " + json.dumps(settings["web"]) + "\n")
            peer = subprocess.Popen(["node", str(here / "peer.cjs"), scratch], cwd=root)
            native_env = dict(os.environ, XPN_JOURNEY_CONFIG_DIR=str(config))
            # Run each platform against its own foreground session, preserving the shared conversation.
            for target in ("testAndroidHostTest", "iosSimulatorArm64Test"):
                subprocess.run(["./gradlew", "--no-configuration-cache", "-I", "scripts/realtime/journey.init.gradle",
                                ":shared:" + target, "--tests", "*RelayJourney"], cwd=native, env=native_env, check=True)
            if peer.wait(timeout=30) != 0:
                raise RuntimeError("Browser peer failed")
    finally:
        stop(peer)
        stop(next_server)
        (control / "stop").touch()
        try:
            server.wait(timeout=12)
        except subprocess.TimeoutExpired:
            stop(server)
