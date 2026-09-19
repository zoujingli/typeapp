#!/usr/bin/env python3
"""独立工具与标准清单；不加载type-mqtt实现，EMQX只在显式差分模式启动。"""
import argparse
import copy
import hashlib
import importlib
import inspect
import json
import os
from pathlib import Path
import pty
import re
import runpy
import select
import signal
import socket
import ssl
import subprocess
import sys
import threading
import time
import unittest
from html.parser import HTMLParser

PAHO = "9d7bb80bb8b9d9cfc0b52f8cb4c1916401281103"
MOSQUITTO = "766fa2c5a9bed6c65249cf555f430b982c417480"
SPECIFICATIONS = {"3.1.1": (141, "c5338d40f81f9270ff5700dc78305140f8d60004c13033c0b63bf6b7c80b9efd"),
                  "5.0": (251, "bbc0a6d1013e14930ef8a2caa03855415332b0d49ec8b03eab2bcb3d2f7917fc")}
ROOT = Path(__file__).resolve().parent.parent
IDENTIFIER = r"MQTT-\d[\d.-]*\d"
ALIASES = {"3.1.1": {"MQTT-3.3.1.-1": "MQTT-3.3.1-1", "MQTT-3.1.2.7": "MQTT-3.1.2-7"},
           "5.0": {"MQTT-4.2-1": "MQTT-4.2.0-1"}}


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


class Statements(HTMLParser):
    """附录只作编号清单，正文段落及§7另行交叉核对；不推定测试覆盖。"""
    def __init__(self):
        super().__init__()
        self.rows, self.cells, self.cell, self.blocks, self.stack = [], [], None, [], []
        self.heading, self.anchor = "", ""

    def handle_starttag(self, tag, attrs):
        if tag == "tr":
            self.cells = []
        if tag == "td":
            self.cell = []
        if tag in ("p", "li", "h1", "h2", "h3", "h4", "h5"):
            self.stack.append([tag, [], self.heading, self.anchor])
        if tag == "a" and self.stack and self.stack[-1][0].startswith("h"):
            self.anchor = dict(attrs).get("name", self.anchor)

    def handle_data(self, data):
        if self.cell is not None:
            self.cell.append(data)
        for block in self.stack:
            block[1].append(data)

    def handle_endtag(self, tag):
        if tag == "td" and self.cell is not None:
            self.cells.append(re.sub(r"\s+", " ", "".join(self.cell)).strip())
            self.cell = None
        if tag == "tr" and len(self.cells) >= 2:
            match = re.fullmatch(r"\[?(" + IDENTIFIER + r")\]?", self.cells[0])
            if match:
                self.rows.append((match[1], " ".join(self.cells[1:])))
        if self.stack and tag == self.stack[-1][0]:
            block = self.stack.pop()
            text = re.sub(r"\s+", " ", "".join(block[1])).strip()
            if tag.startswith("h") or text.startswith("Appendix "):
                self.heading = text
            self.blocks.append((text, block[2], block[3]))


def inventory(args):
    specs, rows = [], []
    for version in ("3.1.1", "5.0"):
        path = args.specs / ("mqtt-v" + version + "-os.html")
        raw = path.read_bytes()
        if digest(path) != SPECIFICATIONS[version][1]:
            raise ValueError("官方标准文档与固定摘要不同，必须人工审查正文后更新清单")
        charset = re.search(br"charset=([\w-]+)", raw[:20000], re.I)
        parser = Statements()
        parser.feed(raw.decode(charset.group(1).decode() if charset else "utf-8", errors="strict"))
        notices, in_notices = [], False
        for paragraph, _, _ in parser.blocks:
            if paragraph.startswith("Copyright © OASIS Open"):
                in_notices = True
            if in_notices and paragraph.startswith("Table of Contents"):
                break
            if in_notices and paragraph:
                notices.append(paragraph)
        if not notices or len(notices) > 30:
            raise ValueError("无法完整保留标准文档的Notices许可声明")
        body = {}
        for text, heading, anchor in parser.blocks:
            if heading.startswith("Appendix "):
                continue
            for raw_id in re.findall(IDENTIFIER, text):
                identifier = ALIASES[version].get(raw_id, raw_id)
                if identifier not in body:
                    body[identifier] = {"paragraph": text, "heading": heading, "anchor": anchor}
            if version == "5.0" and text.endswith("Connect Reason Code valuesT-3.2.2-8]."):
                body["MQTT-3.2.2-8"] = {"paragraph": text, "heading": heading, "anchor": anchor,
                                           "sourceTypo": "T-3.2.2-8]"}
        unique = {ALIASES[version].get(identifier, identifier): statement for identifier, statement in parser.rows}
        if len(unique) != len(parser.rows) or len(unique) != SPECIFICATIONS[version][0] or set(unique) != set(body):
            raise ValueError("官方附录编号重复或清单不完整")
        url = "https://docs.oasis-open.org/mqtt/mqtt/v" + version + "/os/mqtt-v" + version + "-os.html"
        specs.append({"version": version, "url": url, "sha256": digest(path), "numberedStatements": len(unique),
                      "aliases": ALIASES[version], "notices": notices, "appendixOnly": sorted(set(unique) - set(body)), "bodyOnly": sorted(set(body) - set(unique))})
        for identifier, statement in unique.items():
            rows.append({"version": version, "id": identifier, "statement": statement,
                         "body": body.get(identifier), "applicability": "review-required",
                         "status": "not-run", "assertions": [], "evidence": []})
    output = {"status": "incomplete", "specifications": specs,
              "rule": "附录非规范；正文及7.1.1裁定。未审查/未运行/失败不计兼容通过。", "clauses": rows}
    if args.mapping:
        apply_mapping(output, args.mapping)
    args.output.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"specifications": [{key: value for key, value in spec.items() if key != "notices"} for spec in specs],
                      "clauses": len(rows)}, ensure_ascii=False))


def apply_mapping(output, path):
    mapping = json.loads(path.read_text())
    rows = {(row["version"], row["id"]): row for row in output["clauses"]}
    for row in rows.values():
        body = row.pop("body")
        row["section"] = body["heading"] if body else None
        row["anchor"] = body["anchor"] if body else None
        row["requirement"] = body["paragraph"] if body else None
        row["appendixStatementSha256"] = hashlib.sha256(row.pop("statement").encode()).hexdigest()
        row.update(applicability="server", status="not-covered", reason="尚未建立足以覆盖整条要求的断言与有效证据")
    for group in mapping["groups"]:
        for reference in group.get("assertions", []):
            source = (ROOT / reference["file"]).read_text()
            function = re.search(r"^(?:function|def) " + re.escape(reference["function"]) + r"\(", source, re.M)
            remaining = source[function.start():] if function else ""
            boundary = re.search(r"\n(?:function|def) ", remaining)
            scope = remaining[:boundary.start()] if boundary else remaining
            if reference["needle"] not in scope:
                raise ValueError("条款映射引用失效：" + str(reference))
            reference["fileSha256"] = digest(ROOT / reference["file"])
            reference["line"] = source[:function.start() + scope.index(reference["needle"])].count("\n") + 1
        for version in group["versions"]:
            for identifier in group["ids"]:
                row = rows[(version, identifier)]
                row.update({key: group[key] for key in ("applicability", "status", "reason")})
                row["assertions"].extend(group.get("assertions", []))
    for row in rows.values():
        for reference in row["assertions"]:
            evidence = reference.get("evidence") or mapping.get("evidenceByFunction", {}).get(reference["function"])
            if evidence and evidence not in row["evidence"]:
                row["evidence"].append(evidence)
    output["additionalObligations"] = mapping["additionalObligations"]
    output["mappingSha256"] = digest(path)
    output["status"] = "incomplete"


def tls_socket(args, timeout=5):
    context = ssl.create_default_context(cafile=str(args.ca))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    raw = socket.create_connection(("127.0.0.1", args.port), timeout)
    try:
        return context.wrap_socket(raw, server_hostname="127.0.0.1")
    except BaseException:
        raw.close()
        raise


def paho_case(args):
    sys.path.insert(0, str(args.paho / "interoperability"))
    module_name, test_name, *variant = args.case.split(":")
    barrier = variant == ["suback-barrier"]
    if variant and (not barrier or args.mode != "paho-review"
                    or test_name not in ("test_subscribe_options", "test_request_response")):
        raise ValueError("仅复核已定位的两项上游SUBACK等待竞态")
    suite = importlib.import_module(module_name)
    version = "V5" if module_name.endswith("5") else "V311"
    clients = importlib.import_module("mqtt.clients." + version)
    original = clients.Client.connect
    signature = inspect.signature(original)
    transport_errors, transports, traces, attempts = [], [], [], []
    condition = threading.Condition()
    started = time.monotonic_ns()

    def event(connection, direction, wire):
        # 固定测试身份与报文顺序；CONNECT不记录凭据，单项最多1024事件/1MiB正文。
        with condition:
            if len(traces) >= 1024:
                raise ValueError("Paho报文审计超过1024事件预算")
            packet_type = wire[0] >> 4
            item = {"sequence": len(traces) + 1, "nanoseconds": time.monotonic_ns() - started,
                    "connection": connection, "direction": direction, "packetType": packet_type, "bytes": len(wire)}
            if packet_type == 1:
                item["wire"] = "CONNECT-credentials-omitted"
            elif len(wire) <= 4096:
                item["wire"] = wire.hex()
            else:
                item["sha256"] = hashlib.sha256(wire).hexdigest()
            traces.append(item)

    if barrier:
        original_subscribe, original_subscribed = clients.Client.subscribe, suite.Callbacks.subscribed

        def subscribed(callback, identifier, granted):
            original_subscribed(callback, identifier, granted)
            with condition:
                condition.notify_all()

        def subscribe(client, *positional, **keywords):
            identifier = original_subscribe(client, *positional, **keywords)
            if client.callback is not None:
                with condition:
                    ready = condition.wait_for(lambda: any(item[0] == identifier for item in client.callback.subscribeds), timeout=3)
                if not ready:
                    raise TimeoutError("上游客户端未收到匹配Packet Identifier的SUBACK")
            return identifier

        clients.Client.subscribe, suite.Callbacks.subscribed = subscribe, subscribed

    # 只适配真实TLS、明确凭据和有界等待；原测试报文、期望和客户端编解码器保留。
    def connect(client, *positional, **keywords):
        bound = signature.bind(client, *positional, **keywords)
        bound.apply_defaults()
        if bound.arguments["newsocket"]:
            if hasattr(client, "sock"):
                client.sock.close()
            label = str(len(transports) + 1) + ":" + str(client.clientid)[:128]
            observer = (lambda direction, wire: event(label, direction, wire)) if args.mode == "paho-review" else None
            client.sock = paho_tls_socket(args, transport_errors, observer, transports)
            bound.arguments["newsocket"] = False
        bound.arguments["username"] = "example"
        bound.arguments["password"] = os.environ["MQTT_PASSWORD"].encode()
        # MQTT5上游getPacket会显式settimeout(.3)，保留其真实读取行为。
        attempt = {"clientId": str(client.clientid), "clientIdLength": len(client.clientid),
                   "clean": bound.arguments.get("cleansession", bound.arguments.get("cleanstart")),
                   "startedNanoseconds": time.monotonic_ns() - started, "outcome": "pending"}
        if args.mode == "paho-review":
            if len(attempts) >= 128:
                raise ValueError("Paho连接审计超过128次预算")
            attempts.append(attempt)
        try:
            response = original(**bound.arguments)
            attempt["outcome"] = "connected"
            return response
        except BaseException as error:
            attempt["outcome"] = "raised"
            attempt["exceptionType"] = type(error).__name__
            raise
        finally:
            attempt["finishedNanoseconds"] = time.monotonic_ns() - started

    clients.Client.connect = connect
    suite.host, suite.port = "127.0.0.1", args.port
    suite.topic_prefix = "client_test5/"
    suite.topics = ("TopicA", "TopicA/B", "Topic/C", "TopicA/C", "/TopicA")
    suite.wildtopics = ("TopicA/+", "+/C", "#", "/#", "/+", "+/+", "TopicA/#")
    suite.nosubscribe_topics = ("test/nosubscribe",)
    result = unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite([suite.Test(test_name)]))
    for stream, thread in transports:
        try:
            stream.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        stream.close()
        thread.join(timeout=3)
        if thread.is_alive():
            transport_errors.append("Paho TLS线程未在3秒内结束")
    if transport_errors:
        print("TLS transport errors:", transport_errors)
    if args.mode == "paho-review":
        print("PAHO_TRACE " + json.dumps({"case": args.case, "status": "passed" if result.wasSuccessful() and not transport_errors else "failed",
              "upstreamAssertions": "unchanged", "synchronization": "matching-SUBACK" if barrier else "original",
              "readTimeout": "upstream-unchanged; MQTT5-getPacket-overrides-to-0.3-seconds",
              "observationBoundary": "TLS-send-return-and-receive; cross-socket-server-processing-order-not-observed",
              "events": traces, "connectAttempts": attempts, "transportErrors": transport_errors}, ensure_ascii=False))
    return 0 if result.wasSuccessful() and not transport_errors else 1


def paho_tls_socket(args, errors, observer=None, transports=None):
    """上游客户端使用并发TCP读写；同一线程拥有TLS状态，原客户端只看到有界字节流。"""
    secure = tls_socket(args)
    client, bridge = socket.socketpair()
    client.settimeout(0.5)  # 固定上游版本的原始TCP超时。
    bridge.setblocking(False)
    secure.setblocking(False)

    def pump():
        to_remote, to_client = bytearray(), bytearray()
        frames = {"client-to-broker": bytearray(), "broker-to-client": bytearray()}

        def observe(direction, data):
            if observer is None:
                return
            frame = frames[direction]
            frame.extend(data)
            if len(frame) > 2097152:
                raise ValueError("Paho报文审计缓冲超过2MiB")
            while len(frame) >= 2:
                length, multiplier, offset = 0, 1, 1
                while offset < len(frame) and offset <= 4:
                    byte = frame[offset]
                    length += (byte & 127) * multiplier
                    offset += 1
                    if not byte & 128:
                        break
                    multiplier *= 128
                else:
                    if offset > 4:
                        raise ValueError("Paho审计遇到非法Remaining Length")
                    return
                if length > 1048576:
                    raise ValueError("Paho审计报文超过1MiB")
                if len(frame) < offset + length:
                    return
                observer(direction, bytes(frame[:offset + length]))
                del frame[:offset + length]

        local_open, remote_open = True, True
        try:
            while True:
                if (not local_open and not to_remote) or (not remote_open and not to_client):
                    break
                readable = ([bridge] if local_open else []) + ([secure] if remote_open else [])
                writable = ([secure] if to_remote and remote_open else []) + ([bridge] if to_client and local_open else [])
                ready, writes, _ = select.select(readable, writable, [], 0.1)
                if bridge in ready:
                    data = bridge.recv(65536)
                    if not data:
                        local_open = False
                    to_remote.extend(data)
                if remote_open and (secure in ready or secure.pending()):
                    try:
                        data = secure.recv(65536)
                        if not data:
                            remote_open = False
                        observe("broker-to-client", data)
                        to_client.extend(data)
                    except (ssl.SSLWantReadError, ssl.SSLWantWriteError):
                        pass
                if len(to_remote) > 2097152 or len(to_client) > 2097152:
                    raise ValueError("Paho TLS传输缓冲超过2MiB预算")
                if secure in writes:
                    try:
                        sent = secure.send(to_remote)
                        observe("client-to-broker", to_remote[:sent])
                        del to_remote[:sent]
                    except (ssl.SSLWantReadError, ssl.SSLWantWriteError):
                        pass
                if bridge in writes:
                    sent = bridge.send(to_client)
                    del to_client[:sent]
        except (BrokenPipeError, ConnectionResetError):
            pass
        except BaseException as error:
            errors.append(type(error).__name__ + ": " + str(error))
        finally:
            bridge.close()
            secure.close()

    thread = threading.Thread(target=pump, daemon=True)
    thread.start()
    if transports is not None:
        transports.append((client, thread))
    return client


def read_packet(sock):
    first = sock.recv(1)
    if not first:
        return b""
    wire, remaining, multiplier = first, 0, 1
    for _ in range(4):
        byte = sock.recv(1)
        if not byte:
            raise ValueError("收到截断固定头")
        wire += byte
        remaining += (byte[0] & 127) * multiplier
        if not byte[0] & 128:
            break
        multiplier *= 128
    else:
        raise ValueError("收到非法Remaining Length")
    if remaining > 1048576:
        raise ValueError("工具接收预算超限")
    while remaining:
        chunk = sock.recv(remaining)
        if not chunk:
            raise ValueError("收到截断正文")
        wire += chunk
        remaining -= len(chunk)
    return wire


def mosquitto_case(args):
    sys.path[:0] = [str(args.mosquitto / "test"), str(args.mosquitto / "test/broker")]
    module = importlib.import_module("mosq_test")
    original_connect, original_ack = module.gen_connect, module.gen_connack

    def connect(*positional, **keywords):
        keywords.update(username="example", password=os.environ["MQTT_PASSWORD"])
        return original_connect(*positional, **keywords)

    def connack(flags=0, rc=0, proto_ver=4, properties=b"", property_helper=True):
        # 原装置把Mosquitto的别名10/接收20作为默认；改为本次明确部署的能力32/32/1MiB。
        if proto_ver == 5 and rc == 0 and property_helper:
            properties = bytes.fromhex("2100202200202700100000280129012a01") + (properties or b"")
        return original_ack(flags, rc, proto_ver, properties, False)

    def expect_packet(sock, name, expected):
        actual = read_packet(sock)
        # MQTT5 DISCONNECT允许省略零长度属性；只规范化这种等价编码，原因码仍严格比较。
        if len(expected) == 3 and expected[:2] == b"\xe0\x01" and actual == b"\xe0\x02" + expected[2:] + b"\0":
            return True
        return module.packet_matches(name, actual, expected) or (_ for _ in ()).throw(module.TestError())

    class ExternalBroker:
        # 仅适配装置生命周期；被测Broker由PHP所有者启动、关闭和验证，绝不启动Mosquitto。
        def terminate(self): pass
        def wait(self): return 0
        def communicate(self): return b"", b""

    def external(*positional, **keywords):
        if keywords.get("cmd") or keywords.get("use_conf") or keywords.get("expect_fail"):
            raise ValueError("此用例依赖Mosquitto专有启动配置，不适用于外部Broker")
        return ExternalBroker()

    module.gen_connect, module.gen_connack = connect, connack
    module.get_port = lambda count=1: args.port if count == 1 else (_ for _ in ()).throw(ValueError("多Broker未适配"))
    module.start_broker = external
    module.client_connect_only = lambda hostname="localhost", port=1888, timeout=10: tls_socket(args, timeout)
    module.expect_packet = expect_packet
    runpy.run_path(str(args.mosquitto / "test/broker" / args.case), run_name="__main__")
    return 0


def cli_case(args):
    version, qos = args.case.split(":")
    common = ["-h", "127.0.0.1", "-p", str(args.port), "-V", version, "-u", "example", "-P", os.environ["MQTT_PASSWORD"],
              "--cafile", str(args.ca), "-q", qos, "-t", "interop/cli/" + version + "/" + qos]
    # 上游debug回调使用printf且不fflush；PTY保持其终端行缓冲，不能以固定sleep冒充SUBACK。
    terminal, child_terminal = pty.openpty()
    try:
        subscriber = subprocess.Popen([str(args.mosquitto_bin / "mosquitto_sub"), *common, "-C", "1", "-W", "12", "-F", "%x", "-d"],
                                      stdin=subprocess.DEVNULL, stdout=child_terminal, stderr=child_terminal)
    except BaseException:
        os.close(terminal)
        raise
    finally:
        os.close(child_terminal)
    lines, subscriber_errors = [], []
    suback = threading.Event()

    def capture_subscriber():
        try:
            while True:
                line = os.read(terminal, 16384)
                if not line:
                    break
                lines.append(line)
                text = b"".join(lines)
                if len(text) > 2097152:
                    raise ValueError("Mosquitto订阅日志超过2MiB")
                if b"received SUBACK" in text:
                    suback.set()
        except OSError as error:
            if error.errno != 5:
                subscriber_errors.append(str(error))
        except BaseException as error:
            subscriber_errors.append(str(error))

    reader = threading.Thread(target=capture_subscriber, daemon=True)
    reader.start()
    try:
        if not suback.wait(timeout=8):
            raise TimeoutError("Mosquitto未观察到SUBACK：" + b"".join(lines).decode(errors="replace"))
        payload = bytes(range(256)) + b"\x00\xff\xed\xa0\x80"
        publisher = subprocess.run([str(args.mosquitto_bin / "mosquitto_pub"), *common, "-s", "-d"], input=payload,
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=12)
        print(publisher.stdout.decode(errors="replace"))
        if publisher.returncode:
            raise RuntimeError("Mosquitto发布者失败")
        subscriber.wait(timeout=15)
        reader.join(timeout=3)
        text = b"".join(lines)
        print(text.decode(errors="replace"))
        if subscriber.returncode or subscriber_errors or payload.hex().encode() not in text.splitlines():
            raise AssertionError("Mosquitto二进制交付不同或订阅者失败：" + str(subscriber.returncode) + str(subscriber_errors))
    finally:
        if subscriber.poll() is None:
            subscriber.terminate()
            try:
                subscriber.wait(timeout=3)
            except subprocess.TimeoutExpired:
                subscriber.kill()
                subscriber.wait(timeout=3)
        reader.join(timeout=3)
        os.close(terminal)
    return 0


def protocol_probes(args):
    """独立构造单一错误，记录原始响应和实际关闭；理由建议与MUST关闭分开。"""
    def field(value):
        return len(value).to_bytes(2, "big") + value

    def packet(header, payload):
        value, length = len(payload), bytearray()
        while True:
            length.append((value % 128) | (128 if value > 127 else 0))
            value //= 128
            if not value:
                return bytes([header]) + length + payload

    def subscribe(identifier=1, options=0, properties=b"", topic=b"probe/topic", header=0x82):
        return packet(header, identifier.to_bytes(2, "big") + bytes([len(properties)]) + properties + field(topic) + bytes([options]))

    cases = [
        ("subscribe-zero-id", subscribe(identifier=0), 0x82, "2.2.1"),
        ("subscribe-qos3", subscribe(options=3), 0x82, "3.8.3.1"),
        ("subscribe-rh3", subscribe(options=0x30), 0x82, "3.8.3.1"),
        ("subscribe-reserved-options", subscribe(options=0xC0), 0x81, "MQTT-3.8.3-5"),
        ("subscribe-reserved-flags", subscribe(header=0x80), 0x81, "MQTT-3.8.1-1"),
        ("subscribe-wrong-property", subscribe(properties=b"\x11\0\0\0\0"), 0x82, "2.2.2.2"),
        ("subscribe-zero-identifier", subscribe(properties=b"\x0b\0"), 0x82, "3.8.2.1.2"),
        ("subscribe-duplicate-identifier", subscribe(properties=b"\x0b\1\x0b\2"), 0x82, "3.8.2.1.2"),
        ("subscribe-utf8-surrogate", subscribe(topic=b"probe/\xed\xa0\x80"), 0x81, "MQTT-1.5.4-1"),
        ("subscribe-utf8-nul", subscribe(topic=b"probe/\0"), 0x81, "MQTT-1.5.4-2"),
        ("subscribe-truncated-id", b"\x82\1\0", 0x81, "3.8.2"),
        ("subscribe-empty-payload", b"\x82\3\0\1\0", 0x82, "MQTT-3.8.3-2"),
        ("unsubscribe-empty-payload", b"\xa2\3\0\1\0", 0x82, "MQTT-3.10.3-2"),
        ("shared-no-local", subscribe(topic=b"$share/g/probe/topic", options=4), 0x82, "MQTT-3.8.3-4"),
        ("publish-alias-zero", packet(0x30, field(b"probe/topic") + b"\3\x23\0\0x"), 0x94, "3.3.2.3.4"),
        ("publish-client-subscription-id", packet(0x30, field(b"probe/topic") + b"\2\x0b\1x"), 0x82, "MQTT-3.3.4-6"),
        ("ping-nonminimal-length", b"\xc0\x80\0", 0x81, "MQTT-1.5.5-1"),
        ("disconnect-reserved-flags", b"\xe1\0", 0x81, "MQTT-3.14.1-1"),
    ]
    for name, invalid in (("overlong-two", b"\xc0\xaf"), ("overlong-three", b"\xe0\x80\xaf"),
                          ("overlong-four", b"\xf0\x80\x80\xaf"), ("isolated-continuation", b"\x80"),
                          ("above-unicode", b"\xf4\x90\x80\x80"), ("truncated-sequence", b"\xe2\x82")):
        cases.append(("subscribe-utf8-" + name, subscribe(topic=b"probe/" + invalid), 0x81, "MQTT-1.5.4-1"))
    for name, invalid in (("nul", b"x\0y"), ("surrogate", b"\xed\xa0\x80"), ("overlong", b"\xc0\xaf")):
        for position in ("key", "value"):
            pair = field(invalid if position == "key" else b"key") + field(invalid if position == "value" else b"value")
            cases.append(("user-property-" + position + "-" + name, subscribe(properties=b"\x26" + pair), 0x81, "MQTT-1.5.7-1"))
    report = {"version": "5.0", "transport": "TLS-with-verified-peer", "cases": []}
    for name, wire, recommended, clause in cases:
        with tls_socket(args, 3) as stream:
            connect = packet(0x10, field(b"MQTT") + b"\5\xc2\0\x0a\0" + field(("probe-" + name).encode())
                             + field(b"example") + field(os.environ["MQTT_PASSWORD"].encode()))
            stream.sendall(connect)
            connack = read_packet(stream)
            if len(connack) < 4 or connack[0] != 0x20 or connack[2:4] != b"\0\0":
                raise AssertionError("差分前置CONNECT失败：" + connack.hex())
            stream.sendall(wire)
            response, observation_error, closed = b"", None, False
            try:
                response = read_packet(stream)
                closed = not response or read_packet(stream) == b""
            except (OSError, ValueError) as error:
                observation_error = type(error).__name__ + ": " + str(error)
            reason = response[2] if response.startswith(b"\xe0") and len(response) >= 3 else None
            report["cases"].append({"case": name, "clause": clause, "request": wire.hex(), "response": response.hex(),
                                    "closed": closed, "recommendedReason": recommended, "actualReason": reason,
                                    "observationError": observation_error,
                                    "reasonMatches": not response or reason == recommended,
                                    "status": "passed" if closed and (not response or reason == recommended) else "failed"})
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"] == "passed" else 1


def property_cases(args):
    """按OASIS5表2-4/各报文章节构造属性，不调用Broker编码器；确认包先建立真实匹配交换。"""
    def field(value):
        return len(value).to_bytes(2, "big") + value

    def variable(value):
        encoded = bytearray()
        while True:
            encoded.append((value % 128) | (128 if value > 127 else 0))
            value //= 128
            if not value:
                return bytes(encoded)

    def packet(header, body):
        return bytes([header]) + variable(len(body)) + body

    def body(wire):
        offset = 1
        while wire[offset] & 128:
            offset += 1
        return wire[offset + 1:]

    # OASIS5表2-4的全部27种标识，值为该标识独立合法的最小或普通样本。
    values = {0x01: b"\1", 0x02: (30).to_bytes(4, "big"), 0x03: field(b"text/plain"),
              0x08: field(b"properties/response"), 0x09: field(b"\0\xff"), 0x0b: b"\1",
              0x11: b"\0" * 4, 0x12: field(b"assigned"), 0x13: b"\0\x0a", 0x15: field(b"method"),
              0x16: field(b"\0\xff"), 0x17: b"\1", 0x18: b"\0" * 4, 0x19: b"\0", 0x1a: field(b"response"),
              0x1c: field(b"reference"), 0x1f: field(b"reason"), 0x21: b"\0\x04", 0x22: b"\0\x02",
              0x23: b"\0\1", 0x24: b"\1", 0x25: b"\1", 0x26: field(b"key") + field(b"value"),
              0x27: (65536).to_bytes(4, "big"), 0x28: b"\1", 0x29: b"\1", 0x2a: b"\1"}
    # DISCONNECT/0x1c仅观察本部署的Server Reference方向策略；表2-4允许DISCONNECT，
    # §3.14.2.2.5描述Server发送，却未显式要求Client禁用，因此该一行不计为MUST通过。
    positions = {"connect": {0x11, 0x21, 0x27, 0x22, 0x19, 0x17, 0x26, 0x15, 0x16},
                 "will": {0x18, 0x01, 0x02, 0x03, 0x08, 0x09, 0x26},
                 "publish": {0x01, 0x02, 0x03, 0x08, 0x09, 0x23, 0x26},
                 "subscribe": {0x0b, 0x26}, "unsubscribe": {0x26},
                 "puback": {0x1f, 0x26}, "pubrec": {0x1f, 0x26},
                 "pubrel": {0x1f, 0x26}, "pubcomp": {0x1f, 0x26}, "disconnect": {0x11, 0x1f, 0x26}}
    context = args.case
    allowed = positions[context]
    cases = []
    for identifier, value in values.items():
        encoded = variable(identifier) + value
        if identifier not in allowed:
            cases.append((f"wrong-position-{identifier:02x}", encoded, 0x82, None))
        else:
            if identifier != 0x26:
                # Authentication Data要求同包存在Method；避免缺少Method也产生0x82而掩盖重复校验。
                companion = b"\x15" + values[0x15] if context == "connect" and identifier == 0x16 else b""
                cases.append((f"duplicate-{identifier:02x}", companion + encoded + encoded, 0x82, None))
            cases.append((f"truncated-value-{identifier:02x}", encoded[:-1], 0x81, None))
    cases += [("unknown-identifier", b"\x7f", 0x82, None),
              ("nonminimal-identifier", b"\xa6\0" + values[0x26], 0x81, None),
              ("nonminimal-property-length", b"", 0x81, b"\x80\0"),
              ("truncated-property-length", b"", 0x81, b"\x80"),
              ("property-length-overrun", b"", 0x81, variable(1000))]
    for identifier in sorted(allowed & {0x01, 0x17, 0x19}):
        cases.append((f"invalid-boolean-{identifier:02x}", bytes([identifier, 2]), 0x82, None))
    for identifier, value in ((0x21, b"\0\0"), (0x27, b"\0" * 4), (0x0b, b"\0")):
        if identifier in allowed:
            cases.append((f"forbidden-zero-{identifier:02x}", bytes([identifier]) + value, 0x82, None))
    if 0x08 in allowed:
        for name, value in (("empty", b""), ("hash", b"properties/#"), ("plus", b"properties/+")):
            cases.append(("response-topic-" + name, b"\x08" + field(value), 0x82, None))
        for name, value in (("nul", b"x\0y"), ("surrogate", b"\xed\xa0\x80")):
            cases.append(("response-topic-" + name, b"\x08" + field(value), 0x81, None))

    def connect(properties=b"", will_properties=None, property_length=None):
        connect_properties = ((property_length if property_length is not None else variable(len(properties))) + properties)
        payload = field(b"MQTT") + bytes([5, 0xc6 if will_properties is not None else 0xc2, 0, 30])
        payload += connect_properties + field(("properties-" + context).encode())
        if will_properties is not None:
            payload += will_properties + field(b"properties/will") + field(b"will")
        return packet(0x10, payload + field(b"example") + field(os.environ["MQTT_PASSWORD"].encode()))

    def prepare(stream):
        stream.sendall(connect(b"\x19\0\x17\0"))
        connack = read_packet(stream)
        if not connack.startswith(b"\x20") or body(connack)[:2] != b"\0\0":
            raise AssertionError("属性负向前置CONNECT失败：" + connack.hex())
        if context not in ("puback", "pubrec", "pubrel", "pubcomp"):
            return 1
        qos = 1 if context == "puback" else 2
        topic = b"properties/ack"
        if context != "pubrel":
            stream.sendall(packet(0x82, b"\0\1\0" + field(topic) + bytes([0x20 | qos])))
            if read_packet(stream) != packet(0x90, b"\0\1\0" + bytes([qos])):
                raise AssertionError("属性确认前置SUBACK错误")
        stream.sendall(packet(0x30 | qos << 1, field(topic) + b"\x04\xd2\0payload"))
        if context == "pubrel":
            if read_packet(stream) != b"\x50\2\x04\xd2":
                raise AssertionError("属性PUBREL前置PUBREC错误")
            return 1234
        identifier, source_complete = None, False
        for _ in range(4):
            wire = read_packet(stream)
            payload = body(wire)
            if wire[0] >> 4 == 3:
                offset = 2 + int.from_bytes(payload[:2], "big")
                identifier = int.from_bytes(payload[offset:offset + 2], "big")
                if wire[0] != 0x30 | qos << 1 or identifier == 0:
                    raise AssertionError("属性确认前置PUBLISH无效")
            elif wire in (b"\x40\2\x04\xd2", b"\x70\2\x04\xd2"):
                source_complete = True
            elif wire == b"\x50\2\x04\xd2":
                stream.sendall(b"\x62\2\x04\xd2")
            else:
                raise AssertionError("属性确认前置交换出现意外报文：" + wire.hex())
            if identifier is not None and source_complete:
                break
        if identifier is None or not source_complete:
            raise AssertionError("未建立属性确认的实际Packet Identifier")
        if context == "pubcomp":
            stream.sendall(packet(0x50, identifier.to_bytes(2, "big")))
            if read_packet(stream) != packet(0x62, identifier.to_bytes(2, "big")):
                raise AssertionError("属性PUBCOMP前置PUBREL错误")
        return identifier

    report = {"version": "5.0", "context": context, "transport": "TLS-with-verified-peer",
              "rule": "错误原因建议与实际关闭分列；确认报文只在真实匹配交换后注入单一属性错误", "cases": []}
    for name, properties, recommended, length_override in cases:
        row = {"case": context + "-" + name, "propertyBytes": properties.hex(), "recommendedReason": recommended,
               "clause": "2.2.2.1/2.2.2.2及对应报文属性小节", "prerequisite": "not-run", "status": "failed"}
        try:
            with tls_socket(args, 5) as stream:
                encoded = (length_override if length_override is not None else variable(len(properties))) + properties
                if context == "connect":
                    wire = connect(properties, property_length=length_override)
                    row["prerequisite"] = "new-TLS-connection"
                elif context == "will":
                    wire = connect(will_properties=encoded)
                    row["prerequisite"] = "new-TLS-connection"
                else:
                    identifier = prepare(stream)
                    row["prerequisite"] = "matching-exchange" if context.startswith("pub") and context != "publish" else "connected"
                    row["requestProblemInformation"] = 0
                    prefix = identifier.to_bytes(2, "big")
                    if context == "publish": wire = packet(0x30, field(b"properties/topic") + encoded + b"payload")
                    elif context == "subscribe": wire = packet(0x82, prefix + encoded + field(b"properties/topic") + b"\0")
                    elif context == "unsubscribe": wire = packet(0xa2, prefix + encoded + field(b"properties/topic"))
                    elif context == "disconnect": wire = packet(0xe0, b"\0" + encoded)
                    else: wire = packet({"puback": 0x40, "pubrec": 0x50, "pubrel": 0x62, "pubcomp": 0x70}[context], prefix + b"\0" + encoded)
                row["request"] = "CONNECT-credentials-omitted" if context in ("connect", "will") else wire.hex()
                stream.sendall(wire)
                response = read_packet(stream)
                row["response"] = response.hex()
                row["closed"] = not response or read_packet(stream) == b""
                expected_type = 0x20 if context in ("connect", "will") else 0xe0
                response_body = body(response) if response else b""
                reason = response_body[1 if expected_type == 0x20 else 0] if response_body else None
                row["actualReason"] = reason
                row["reasonMatches"] = bool(response) and response[0] == expected_type and reason == recommended
                row["reasonStatus"] = "omitted-before-close" if not response else "observed"
                row["status"] = "passed" if row["closed"] and (not response or row["reasonMatches"]) else "failed"
        except (OSError, ValueError, AssertionError, IndexError) as error:
            row["error"] = type(error).__name__ + ": " + str(error)
        report["cases"].append(row)
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"] == "passed" else 1


def flow_cases(args):
    """独立线编码观察§4.9额度及§4.13.2关闭；复用真实TLS/同步存储外层装置。"""
    def field(value):
        return len(value).to_bytes(2, "big") + value

    def packet(header, value):
        length, encoded = len(value), bytearray()
        while True:
            encoded.append(length % 128 | (128 if length > 127 else 0))
            length //= 128
            if not length:
                return bytes([header]) + bytes(encoded) + value

    def body(wire):
        offset = 1
        while wire[offset] & 128:
            offset += 1
        return wire[offset + 1:]

    report = {"version": "5.0", "transport": "TLS-with-verified-peer", "cases": []}
    scenarios = [("puback-success", 1, 0), ("puback-error", 1, 0x80),
                 ("pubcomp-success", 2, 0), ("pubcomp-not-found", 2, 0x92),
                 ("pubrec-error", 2, 0x87), ("disconnect-zero-quota", 1, 0)] if args.case == "send-quota" else [("quota-close", 1, 0)]
    for name, qos, reason in scenarios:
        row = {"case": name, "status": "failed", "receiveMaximum": 2, "quietObservationSeconds": 0.3,
               "sessionExpiry": 0 if name == "quota-close" else 60, "events": [], "checks": []}
        streams = []

        def send(stream, label, wire):
            stream.sendall(wire)
            row["events"].append({"peer": label, "direction": "client-to-broker",
                                  "wire": "CONNECT-credentials-omitted" if wire[0] == 0x10 else wire.hex()})

        def receive(stream, label, expected=None, failure="流控响应与预期不同"):
            wire = read_packet(stream)
            row["events"].append({"peer": label, "direction": "broker-to-client", "wire": wire.hex(), "eof": not wire})
            if expected is not None and wire != expected:
                raise AssertionError(failure + ": " + wire.hex())
            return wire

        def connect(label, expiry=0, maximum=32):
            stream = tls_socket(args, 5)
            streams.append(stream)
            properties = b"\x11" + expiry.to_bytes(4, "big") + b"\x21" + maximum.to_bytes(2, "big") + b"\x17\0"
            wire = packet(0x10, field(b"MQTT") + b"\5\xc2\0\x1e" + bytes([len(properties)]) + properties
                          + field(("flow-" + name + "-" + label).encode())
                          + field(b"example") + field(os.environ["MQTT_PASSWORD"].encode()))
            send(stream, label, wire)
            ack = receive(stream, label)
            if not ack.startswith(b"\x20") or body(ack)[:2] != b"\0\0":
                raise AssertionError("流控前置CONNECT失败")
            return stream

        topic = ("flow/" + name).encode()

        def publication(index, level=qos, target=topic):
            return packet(0x30 | level << 1, field(target) + (index.to_bytes(2, "big") if level else b"") + b"\0" + ("m" + str(index)).encode())

        def published(stream, label, index, level=qos, target=topic):
            send(stream, label, publication(index, level, target))
            receive(stream, label, packet(0x40 if level == 1 else 0x50, index.to_bytes(2, "big")), "流控发布未接管")
            if level == 2:
                send(stream, label, packet(0x62, index.to_bytes(2, "big")))
                receive(stream, label, packet(0x70, index.to_bytes(2, "big")), "流控发布未完成PUBCOMP")

        def delivery(index):
            wire = receive(subscriber, "subscriber")
            value = body(wire)
            offset = 2 + len(topic)
            identifier = int.from_bytes(value[offset:offset + 2], "big")
            expected = packet(0x30 | qos << 1, field(topic) + identifier.to_bytes(2, "big") + b"\0" + ("m" + str(index)).encode())
            if not identifier or wire != expected:
                raise AssertionError("额度允许的交付内容或QoS错误：" + wire.hex())
            return identifier

        def blocked(check):
            send(subscriber, "subscriber", b"\xc0\0")
            receive(subscriber, "subscriber", b"\xd0\0", "零额度时未处理PING或提前投递额外PUBLISH")
            quiet(subscriber, "subscriber")
            row["checks"].append(check)

        def quiet(stream, label):
            # PING不代表异步路由工作已结束；另在有界窗口观察任何首字节，不能吞掉半包超时。
            timeout = stream.gettimeout()
            stream.settimeout(row["quietObservationSeconds"])
            try:
                byte = stream.recv(1)
            except socket.timeout:
                row["events"].append({"peer": label, "direction": "broker-to-client", "observation": "no-byte-within-quiet-window"})
            else:
                raise AssertionError("静默窗口内出现额外报文或连接关闭：" + byte.hex())
            finally:
                stream.settimeout(timeout)

        def acknowledge(identifier):
            prefix = identifier.to_bytes(2, "big")
            if qos == 2 and name != "pubrec-error":
                send(subscriber, "subscriber", packet(0x50, prefix))
                receive(subscriber, "subscriber", packet(0x62, prefix), "QoS2成功PUBREC后未处理PUBREL")
                blocked("positive-PUBREC-does-not-replenish")
            head = 0x40 if qos == 1 else (0x50 if name == "pubrec-error" else 0x70)
            send(subscriber, "subscriber", packet(head, prefix + (bytes([reason, 0]) if reason else b"")))

        try:
            subscriber = connect("subscriber", row["sessionExpiry"], 2)
            publisher = connect("publisher")
            send(subscriber, "subscriber", packet(0x82, b"\0\1\0" + field(topic) + bytes([0x20 | qos])))
            receive(subscriber, "subscriber", packet(0x90, b"\0\1\0" + bytes([qos])), "流控前置SUBACK失败")
            identifiers = []
            for index in (1, 2):
                published(publisher, "publisher", index)
                identifiers.append(delivery(index))
            if len(set(identifiers)) != 2:
                raise AssertionError("并行发送交换复用了Packet Identifier")
            row["checks"].append("two-deliveries-before-any-ack")
            if name == "quota-close":
                observer = connect("observer")
                marker = topic + b"/after-refusal"
                send(observer, "observer", packet(0x82, b"\0\1\0" + field(marker) + b"\x20"))
                receive(observer, "observer", b"\x90\4\0\1\0\0")
                # 单次TLS写入：满额发布后合并PING、另一个Topic的QoS0发布及SUBSCRIBE。
                combined = (publication(3) + b"\xc0\0" + publication(4, 0, marker)
                            + packet(0x82, b"\0\x64\0" + field(marker) + b"\x20"))
                send(publisher, "publisher", combined)
                receive(publisher, "publisher", b"\xe0\2\x97\0", "零期限窗口满未返回Quota exceeded")
                receive(publisher, "publisher", b"", "0x97后没有实际EOF或仍处理合并控制报文")
                blocked("refused-publication-not-delivered")
                send(observer, "observer", b"\xc0\0")
                receive(observer, "observer", b"\xd0\0", "资源拒绝后仍处理合并的后续发布")
                quiet(observer, "observer")
                row["checks"].extend(["quota-reason-0x97", "network-eof", "coalesced-ping-subscribe-publish-not-processed"])
                for identifier in identifiers:
                    send(subscriber, "subscriber", packet(0x40, identifier.to_bytes(2, "big")))
            else:
                for index in (3, 4):
                    published(publisher, "publisher", index)
                blocked("queued-third-and-fourth-not-sent-at-zero-quota")
                auxiliary = topic + b"/control"
                send(subscriber, "subscriber", packet(0x82, b"\x9c\x40\0" + field(auxiliary) + b"\x20"))
                receive(subscriber, "subscriber", b"\x90\4\x9c\x40\0\0", "零额度时未处理SUBSCRIBE")
                send(subscriber, "subscriber", packet(0xa2, b"\x9c\x41\0" + field(auxiliary)))
                receive(subscriber, "subscriber", b"\xb0\4\x9c\x41\0\0", "零额度时未处理UNSUBSCRIBE")
                for level in (1, 2):
                    published(subscriber, "subscriber", 50000 + level, level, auxiliary)
                row["checks"].append("zero-quota-subscribe-unsubscribe-inbound-qos1-qos2-and-pubrel")
                if name == "disconnect-zero-quota":
                    send(subscriber, "subscriber", b"\xe0\7\0\5\x11\0\0\0\0")
                    receive(subscriber, "subscriber", b"", "零额度时未处理DISCONNECT并实际关闭")
                    row["checks"].append("zero-quota-disconnect-eof")
                else:
                    acknowledge(identifiers[0])
                    third = delivery(3)
                    blocked("one-terminal-ack-releases-exactly-one-delivery")
                    acknowledge(identifiers[1])
                    fourth = delivery(4)
                    blocked("second-terminal-ack-releases-next-delivery")
                    for identifier in (third, fourth):
                        # 队列已排空；后续确认不再把PING作为零额度证明。
                        prefix = identifier.to_bytes(2, "big")
                        if qos == 2 and name != "pubrec-error":
                            send(subscriber, "subscriber", packet(0x50, prefix))
                            receive(subscriber, "subscriber", packet(0x62, prefix))
                        head = 0x40 if qos == 1 else (0x50 if name == "pubrec-error" else 0x70)
                        send(subscriber, "subscriber", packet(head, prefix + (bytes([reason, 0]) if reason else b"")))
                    row["checks"].append("terminal-ack-replenishment-observed")
            row["status"] = "passed"
        except (OSError, ValueError, AssertionError, IndexError) as error:
            row["error"] = type(error).__name__ + ": " + str(error)
        finally:
            for stream in streams:
                stream.close()
        report["cases"].append(row)
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"] == "passed" else 1


def payload_cases(args):
    """区分UTF-8 Character Data与Encoded String，逐字节观察PUBLISH和Will转发。"""
    def field(value):
        return len(value).to_bytes(2, "big") + value

    def variable(value):
        encoded = bytearray()
        while True:
            encoded.append(value % 128 | (128 if value > 127 else 0))
            value //= 128
            if not value:
                return bytes(encoded)

    def packet(header, value):
        return bytes([header]) + variable(len(value)) + value

    topic = b"properties/payload"
    # NUL在UTF-8 Character Data中合法；这里不复用禁止NUL的Encoded String验证器。
    samples = [("pfi-absent-binary", b"", bytes(range(256)) + b"\xc0\xaf\xed\xa0\x80"),
               ("pfi-zero-binary", b"\1\0", bytes(range(256)) + b"\xff"),
               ("pfi-one-empty", b"\1\1", b""), ("pfi-one-nul", b"\1\1", b"a\0b"),
               ("pfi-one-bom", b"\1\1", b"\xef\xbb\xbftext"),
               ("pfi-one-boundaries", b"\1\1", "\u0000\u007f\u0080\u07ff\u0800\ud7ff\ue000\uffff\U00010000\U0010ffff".encode()),
               ("pfi-one-no-normalization", b"\1\1", "é/e\u0301".encode())]
    properties = (b"\x03" + field(b"application/octet-stream") + b"\x08" + field(b"properties/response")
                  + b"\x09" + field(b"\0\xff\xc0\xaf")
                  + b"\x26" + field(b"same") + field(b"first") + b"\x26" + field(b"same") + field(b"second"))

    def connect(client_id, will=None):
        # RequestResponseInformation=0与RequestProblemInformation=0；PUBLISH仍允许转发User Property。
        value = field(b"MQTT") + bytes([5, 0xc6 if will else 0xc2, 0, 30]) + b"\4\x19\0\x17\0" + field(client_id)
        if will is not None:
            will_properties, payload = will
            value += variable(len(will_properties)) + will_properties + field(topic) + field(payload)
        return packet(0x10, value + field(b"example") + field(os.environ["MQTT_PASSWORD"].encode()))

    def accepted(stream, request):
        stream.sendall(request)
        actual = read_packet(stream)
        # 独立部署广告的属性字节序固定；没有Response Information(0x1a)。
        advertised = bytes.fromhex("2100202200202700100000280129012a01")
        expected = packet(0x20, b"\0\0" + variable(len(advertised)) + advertised)
        if actual != expected:
            raise AssertionError("RequestResponseInformation=0时CONNACK属性不符合部署：" + actual.hex())
        return actual.hex()

    report = {"version": "5.0", "transport": "TLS-with-verified-peer", "cases": []}
    for origin in ("publish", "will"):
        for name, pfi, payload in samples:
            row = {"case": origin + "-" + name, "status": "failed", "payload": payload.hex(), "properties": (pfi + properties).hex()}
            try:
                with tls_socket(args, 5) as subscriber, tls_socket(args, 5) as publisher:
                    accepted(subscriber, connect(b"payload-subscriber"))
                    subscriber.sendall(packet(0x82, b"\0\1\0" + field(topic) + b"\x20"))
                    if read_packet(subscriber) != b"\x90\4\0\1\0\0":
                        raise AssertionError("Payload前置SUBACK未保持零属性或订阅未成功")
                    application_properties = pfi + properties
                    accepted(publisher, connect(b"payload-publisher", (application_properties, payload) if origin == "will" else None))
                    if origin == "will":
                        publisher.sendall(b"\xe0\2\4\0")
                        if read_packet(publisher) != b"":
                            raise AssertionError("发布Will的DISCONNECT未实际关闭")
                    else:
                        publisher.sendall(packet(0x30, field(topic) + variable(len(application_properties)) + application_properties + payload))
                    actual = read_packet(subscriber)
                    expected = packet(0x30, field(topic) + variable(len(application_properties)) + application_properties + payload)
                    row["response"] = actual.hex()
                    if actual != expected:
                        raise AssertionError("Payload/PFI/响应主题/二进制关联数据/重复User Property顺序发生变化")
                    subscriber.sendall(b"\xc0\0")
                    if read_packet(subscriber) != b"\xd0\0":
                        raise AssertionError("Payload后边界出现多余交付")
                    row["status"] = "passed"
            except (OSError, ValueError, AssertionError) as error:
                row["error"] = type(error).__name__ + ": " + str(error)
            report["cases"].append(row)
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"] == "passed" else 1


def ordered_cases(args):
    """实际SUBACK后连续发布，无retain且RH=2排除保留重放；观察同源/Topic/QoS的交付顺序。"""
    def field(value):
        return len(value).to_bytes(2, "big") + value

    def packet(header, body):
        if len(body) >= 128:
            raise ValueError("有序Topic夹具只编码短报文")
        return bytes([header, len(body)]) + body

    report = {"transport": "TLS-with-verified-peer", "cases": []}
    for version in (4, 5):
        observed = {"case": "ordered-non-retained-" + str(version), "version": "3.1.1" if version == 4 else "5.0",
                    "status": "failed", "subscriptionReady": False, "sent": [], "deliveries": [], "acknowledgments": []}
        try:
            with tls_socket(args) as publisher, tls_socket(args) as subscriber:
                for role, stream in (("publisher", publisher), ("subscriber", subscriber)):
                    body = field(b"MQTT") + bytes([version, 0xc2, 0, 10]) + (b"\0" if version == 5 else b"")
                    stream.sendall(packet(0x10, body + field(("order-" + role + "-" + str(version)).encode())
                                          + field(b"example") + field(os.environ["MQTT_PASSWORD"].encode())))
                    connack = read_packet(stream)
                    if len(connack) < 4 or connack[0] != 0x20 or connack[2:4] != b"\0\0":
                        raise AssertionError("有序Topic前置连接未成功")
                topic = ("probe/order/" + str(version)).encode()
                subscriber.sendall(packet(0x82, b"\0\1" + (b"\0" if version == 5 else b"") + field(topic)
                                          + (b"\x21" if version == 5 else b"\1")))
                suback = read_packet(subscriber)
                if suback != packet(0x90, b"\0\1" + (b"\0" if version == 5 else b"") + b"\1"):
                    raise AssertionError("有序Topic未收到匹配成功SUBACK：" + suback.hex())
                observed["subscriptionReady"] = True
                packets = [packet(0x32, field(topic) + (100 + index).to_bytes(2, "big")
                                  + (b"\0" if version == 5 else b"") + ("m" + str(index)).encode()) for index in range(8)]
                observed["sent"] = [wire.hex() for wire in packets]
                publisher.sendall(b"".join(packets))
                for _ in range(8):
                    wire = read_packet(subscriber)
                    observed["deliveries"].append(wire.hex())
                    offset = 2 + len(field(topic))
                    identifier = wire[offset:offset + 2]
                    if len(identifier) != 2 or int.from_bytes(identifier, "big") == 0 or wire[:1] != b"\x32":
                        raise AssertionError("有序Topic收到非首次QoS1 PUBLISH：" + wire.hex())
                    subscriber.sendall(packet(0x40, identifier))
                for _ in range(8):
                    wire = read_packet(publisher)
                    observed["acknowledgments"].append(wire.hex())
                expected_acks = {packet(0x40, (100 + index).to_bytes(2, "big")).hex() for index in range(8)}
                if len(set(observed["acknowledgments"])) != 8 or set(observed["acknowledgments"]) != expected_acks:
                    raise AssertionError("有序Topic未逐项确认全部原始发布")
                for index, encoded in enumerate(observed["deliveries"]):
                    wire = bytes.fromhex(encoded)
                    expected = packet(0x32, field(topic) + wire[offset:offset + 2]
                                      + (b"\0" if version == 5 else b"") + ("m" + str(index)).encode())
                    if wire != expected:
                        raise AssertionError("MQTT有序Topic同一来源/Topic/QoS交付顺序改变")
                for stream in (publisher, subscriber):
                    stream.sendall(b"\xc0\0")
                    if read_packet(stream) != b"\xd0\0":
                        raise AssertionError("有序Topic出现多余交付或错误关闭")
                observed["status"] = "passed"
        except (OSError, ValueError, AssertionError) as error:
            observed["error"] = type(error).__name__ + ": " + str(error)
        report["cases"].append(observed)
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"] == "passed" else 1


def emqx_case(args):
    """只运行显式目录中的前台EMQX；所有网络入口限定loopback，退出回收专属进程组。"""
    if not args.emqx or not (args.emqx / "bin/emqx").is_file() or not args.output:
        raise ValueError("EMQX差分必须指定新隔离安装目录与输出目录")
    if (args.emqx / "releases/start_erl.data").read_text().split()[-1] != "6.3.0":
        raise ValueError("EMQX差分未使用固定6.3.0版本")
    args.output.mkdir(parents=True, exist_ok=False)
    adapter = args.output / "adapter.py"
    adapter.write_bytes(Path(__file__).read_bytes())

    def port():
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            return listener.getsockname()[1]

    endpoint, rpc, distribution = port(), port(), port()
    environment = dict(os.environ)
    environment.update({
        "EMQX_NODE__NAME": "iot_conformance@127.0.0.1", "EMQX_NODE__COOKIE": "iot-conformance-isolated-cookie",
        "EMQX_NODE__DIST_BIND_ADDRESS": "127.0.0.1", "EMQX_NODE__SCHEDULERS": "2", "EMQX_NODE__MAX_PORTS": "4096",
        "EMQX_RPC__SERVER_PORT": str(rpc), "EMQX_RPC__LISTEN_ADDRESS": "127.0.0.1",
        "ERL_FLAGS": "-kernel inet_dist_listen_min " + str(distribution) + " inet_dist_listen_max " + str(distribution),
        "EMQX_FEATURES": "ESSENTIAL", "EMQX_SECURITY_PROFILE": "legacy",
        "EMQX_LISTENERS__TCP__DEFAULT__ENABLE": "false", "EMQX_LISTENERS__WS__DEFAULT__ENABLE": "false",
        "EMQX_LISTENERS__WSS__DEFAULT__ENABLE": "false", "EMQX_LISTENERS__SSL__DEFAULT__ENABLE": "true",
        "EMQX_LISTENERS__SSL__DEFAULT__BIND": "127.0.0.1:" + str(endpoint),
        "EMQX_LISTENERS__SSL__DEFAULT__ENABLE_AUTHN": "false",
        "EMQX_LISTENERS__SSL__DEFAULT__SSL_OPTIONS__CERTFILE": str(args.ca.resolve()),
        "EMQX_LISTENERS__SSL__DEFAULT__SSL_OPTIONS__KEYFILE": str(args.ca.with_name("private.pem").resolve()),
        "EMQX_DASHBOARD__LISTENERS__HTTP__BIND": "0", "EMQX_DASHBOARD__LISTENERS__HTTPS__BIND": "0",
        "EMQX_AUTHORIZATION__NO_MATCH": "allow", "EMQX_AUTHORIZATION__SOURCES": "[]",
        "EMQX_LOG__CONSOLE__LEVEL": "error"
    })
    output, exceeded = bytearray(), []
    process = subprocess.Popen([str((args.emqx / "bin/emqx").resolve()), "foreground"], cwd=args.emqx,
                               env=environment, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                               stderr=subprocess.STDOUT, start_new_session=True, bufsize=0)

    def capture():
        while True:
            chunk = process.stdout.read(16384)
            if not chunk:
                return
            output.extend(chunk[:2097152 - len(output)])
            if len(output) == 2097152:
                exceeded.append(True)
                os.killpg(process.pid, signal.SIGTERM)
                return

    reader = threading.Thread(target=capture, daemon=True)
    reader.start()
    try:
        deadline = time.monotonic() + 60
        probe = copy.copy(args)
        probe.port = endpoint
        while time.monotonic() < deadline:
            if process.poll() is not None or exceeded:
                raise RuntimeError("EMQX前台装置启动失败；详见独立日志")
            try:
                with tls_socket(probe, 0.5):
                    break
            except (OSError, ssl.SSLError):
                time.sleep(0.1)
        else:
            raise TimeoutError("EMQX TLS入口60秒内未就绪")
        command = [sys.executable, str(adapter.resolve()), "probe", "--port", str(endpoint), "--ca", str(args.ca)]
        captured, code, status = bounded_case(command, 90)
        (args.output / "probes.json").write_bytes(captured)
        clients = []
        if args.case == "cli":
            for version in ("mqttv311", "mqttv5"):
                for qos in range(3):
                    case = version + ":" + str(qos)
                    command = [sys.executable, str(adapter.resolve()), "cli", "--case", case,
                               "--port", str(endpoint), "--ca", str(args.ca), "--mosquitto-bin", str(args.mosquitto_bin)]
                    captured, client_code, client_status = bounded_case(command, 45)
                    path = args.output / (case.replace(":", "-") + ".log")
                    path.write_bytes(captured.replace(str(ROOT).encode(), b"<PROJECT>"))
                    clients.append({"case": case, "exitCode": client_code, "status": client_status, "sha256": digest(path)})
            (args.output / "clients.json").write_text(json.dumps(clients, ensure_ascii=False, indent=2) + "\n")
        summary = {"emqx": "6.3.0", "adapterSha256": digest(adapter), "probesExitCode": code, "probeProcessStatus": status,
                   "sha256": digest(args.output / "probes.json"), "clients": clients}
    finally:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=3)
        # 前台入口退出不自动证明整个专属进程组归零。
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        reader.join(timeout=3)
        process.stdout.close()
        (args.output / "broker.log").write_bytes(bytes(output).replace(str(ROOT).encode(), b"<PROJECT>"))
        cleanup = {"foregroundExitCode": process.returncode, "logReaderStopped": not reader.is_alive(), "listeners": {}}
        for name, value in (("tls", endpoint), ("rpc", rpc), ("distribution", distribution)):
            with socket.socket() as check:
                check.settimeout(0.5)
                cleanup["listeners"][name] = "closed" if check.connect_ex(("127.0.0.1", value)) else "still-listening"
        (args.output / "cleanup.json").write_text(json.dumps(cleanup, indent=2) + "\n")
    summary["cleanup"] = cleanup
    summary["status"] = "passed" if (code == 0 and status == "passed" and not exceeded
        and all(client["status"] == "passed" for client in clients) and cleanup["logReaderStopped"]
        and all(value == "closed" for value in cleanup["listeners"].values())) else "incomplete"
    (args.output / "report.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(summary, ensure_ascii=False))
    return 0 if summary["status"] == "passed" else 1


def bounded_case(command, seconds):
    """独立进程组，真实限制日志内存；成功、超时和父装置取消都回收子客户端。"""
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                               stdin=subprocess.DEVNULL, start_new_session=True, bufsize=0)
    output, status = bytearray(), None
    deadline = time.monotonic() + seconds
    try:
        while True:
            if time.monotonic() >= deadline:
                status = "timeout"
                break
            if select.select([process.stdout], [], [], 0.1)[0]:
                chunk = os.read(process.stdout.fileno(), 16384)
                if len(output) + len(chunk) > 2097152:
                    output.extend(chunk[:2097152 - len(output)])
                    status = "output-limit"
                    break
                output.extend(chunk)
                if not chunk:
                    code = process.wait(timeout=3)
                    status = "passed" if code == 0 else "failed"
                    break
    finally:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            pass
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait(timeout=3)
        process.stdout.close()
    return bytes(output), process.returncode, status


def run(args):
    selected = set(args.select.split(","))
    if not selected or selected - {"cli", "paho", "paho-review", "mosquitto", "probe", "order", "properties", "payload", "flow"}:
        raise ValueError("未知独立工具选择")
    for directory, expected in ((args.paho, PAHO), (args.mosquitto, MOSQUITTO)):
        actual = subprocess.check_output(["git", "-C", str(directory), "rev-parse", "HEAD"], text=True).strip()
        if actual != expected or subprocess.check_output(["git", "-C", str(directory), "status", "--porcelain", "--untracked-files=normal"]):
            raise ValueError("第三方测试源码未固定或已修改")
    args.output.mkdir(parents=True, exist_ok=False)
    adapter = args.output / "adapter.py"
    adapter.write_bytes(Path(__file__).read_bytes())
    cases = [("cli", version + ":" + str(qos)) for version in ("mqttv311", "mqttv5") for qos in range(3)]
    for name in ("client_test", "client_test5"):
        source = (args.paho / "interoperability" / (name + ".py")).read_text()
        cases += [("paho", name + ":" + test) for test in re.findall(r"^    def (test\w+)\(", source, re.M)]
    cases += [("mosquitto", name) for name in ("02-subscribe-invalid-utf8.py", "01-connect-disconnect-v5.py", "13-malformed-subscribe-v5.py")]
    cases += [("probe", "oasis-error-classification")]
    if "paho-review" in selected:
        cases += [("paho-review", "client_test:test_zero_length_clientid")]
        cases += [("paho-review", "client_test5:" + test) for test in
                  ("test_subscribe_failure", "test_server_keep_alive", "test_flow_control1", "test_subscribe_options", "test_request_response")]
        cases += [("paho-review", "client_test5:" + test + ":suback-barrier") for test in
                  ("test_subscribe_options", "test_request_response")]
    if "order" in selected:
        cases += [("order", "oasis-ordered-topic")]
    if "properties" in selected:
        cases += [("properties", name) for name in ("connect", "will", "publish", "subscribe", "unsubscribe", "puback", "pubrec", "pubrel", "pubcomp", "disconnect")]
    if "payload" in selected:
        cases += [("payload", "oasis-character-data")]
    if "flow" in selected:
        cases += [("flow", name) for name in ("send-quota", "quota-close")]
    selected_cases = set(args.case.split(",")) if args.case else set()
    if selected_cases - {kind + ":" + name for kind, name in cases}:
        raise ValueError("筛选用例不在当前已发现清单：" + args.case)
    if not 1 <= args.repeat <= 10 or args.repeat > 1 and (not selected_cases or any(not name.startswith("paho-review:") for name in selected_cases)):
        raise ValueError("重复诊断仅接受1至10次，并须显式筛选paho-review用例")
    cases = [(kind, name, iteration) for kind, name in cases for iteration in
             range(1, (args.repeat if kind + ":" + name in selected_cases else 1) + 1)]
    report = {"status": "incomplete", "transport": "TLS-with-verified-peer", "paho": PAHO, "mosquitto": MOSQUITTO,
              "adapterSha256": digest(adapter),
              "selectedTools": sorted(selected),
              "selectedCase": args.case,
              "repetitions": args.repeat,
              "budgets": {"caseSeconds": 90, "totalSeconds": 1200, "caseLogBytes": 2097152},
              "tools": {name: digest(args.mosquitto_bin / name) for name in ("mosquitto_pub", "mosquitto_sub")}, "cases": []}
    deadline = time.monotonic() + 1200
    for kind, name, iteration in cases:
        repeated = args.repeat > 1 and kind + ":" + name in selected_cases
        logname = kind + "-" + name.replace(":", "-") + ("-run-" + str(iteration) if repeated else "") + ".log"
        command = [sys.executable, str(adapter.resolve()), kind, "--case", name, "--port", str(args.port),
                   "--ca", str(args.ca), "--paho", str(args.paho), "--mosquitto", str(args.mosquitto), "--mosquitto-bin", str(args.mosquitto_bin)]
        start = time.monotonic()
        if kind not in selected or selected_cases and kind + ":" + name not in selected_cases:
            output, code, status = b"Tool or case not selected; case not run.\n", None, "not-run"
        elif time.monotonic() >= deadline:
            output, code, status = b"Total suite time budget exhausted; case not run.\n", None, "not-run"
        else:
            output, code, status = bounded_case(command, min(90, deadline - time.monotonic()))
        output = output.replace(os.environ["MQTT_PASSWORD"].encode(), b"<TEST-CREDENTIAL>")
        output = output.replace(str(ROOT).encode(), b"<PROJECT>")
        (args.output / logname).write_bytes(output)
        result = {"tool": kind, "case": name, "iteration": iteration, "status": status, "exitCode": code,
                  "seconds": round(time.monotonic() - start, 3), "log": logname, "sha256": digest(args.output / logname)}
        if kind == "paho-review" and status not in ("not-run", "timeout", "output-limit"):
            trace = [line.removeprefix(b"PAHO_TRACE ") for line in output.splitlines() if line.startswith(b"PAHO_TRACE ")]
            if len(trace) != 1:
                result["status"], result["traceError"] = "failed", "missing-or-duplicate-Paho-trace"
            else:
                result["trace"] = json.loads(trace[0])
        report["cases"].append(result)
        (args.output / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
        print(kind, name, status, flush=True)
    report["status"] = "passed" if all(row["status"] == "passed" for row in report["cases"]) else "incomplete"
    (args.output / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    return 0 if report["status"] == "passed" else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("inventory", "run", "paho", "paho-review", "mosquitto", "cli", "probe", "order", "properties", "payload", "flow", "emqx"))
    parser.add_argument("--port", type=int)
    parser.add_argument("--ca", type=Path)
    parser.add_argument("--paho", type=Path, default=ROOT / "build/paho-testing")
    parser.add_argument("--mosquitto", type=Path, default=ROOT / "build/mosquitto")
    parser.add_argument("--mosquitto-bin", type=Path, default=ROOT / "build/mosquitto-build/client")
    parser.add_argument("--specs", type=Path, default=ROOT / "build")
    parser.add_argument("--mapping", type=Path)
    parser.add_argument("--emqx", type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--case", default=os.environ.get("TYPE_MQTT_CONFORMANCE_CASE"))
    parser.add_argument("--repeat", type=int, default=int(os.environ.get("TYPE_MQTT_CONFORMANCE_REPEAT", "1")))
    parser.add_argument("--select", default="cli,paho,mosquitto,probe")
    args = parser.parse_args()
    if args.mode == "inventory":
        if not args.output:
            parser.error("inventory必须指定--output")
        return inventory(args)
    if (args.mode != "emqx" and (not args.port or not 0 < args.port < 65536)) or not args.ca or not args.ca.is_file():
        parser.error("必须指定隔离Broker端口和真实CA文件")
    if args.mode in ("run", "emqx"):
        if not args.output:
            parser.error("run/emqx必须指定--output")
        signal.signal(signal.SIGTERM, lambda signum, frame: sys.exit(143))
    elif args.mode not in ("probe", "order", "emqx") and not args.case:
        parser.error("独立用例必须指定--case")
    return {"run": run, "paho": paho_case, "paho-review": paho_case, "mosquitto": mosquitto_case, "cli": cli_case,
            "probe": protocol_probes, "order": ordered_cases, "properties": property_cases, "payload": payload_cases, "flow": flow_cases, "emqx": emqx_case}[args.mode](args)


if __name__ == "__main__":
    sys.exit(main())
