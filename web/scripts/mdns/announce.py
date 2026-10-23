#!/usr/bin/env python3
"""Anunciador DNS-SD periódico para EcoStation (`_ecostation._tcp.local`).

Por qué existe
--------------
avahi solo RESPONDE consultas. En algunas redes Wi-Fi la multidifusión
procedente de otros equipos nunca llega a este host (medido en banco: la
consulta `_ecostation._tcp` del ESP32 no llegaba, mientras que la
multidifusión enviada DESDE este host sí le llegaba). Un equipo cuya
pregunta se pierde nunca recibe respuesta y cae a su URL compilada.

Por eso este script envía la respuesta sin que se la pidan, cada segundo,
en la dirección que funciona: una respuesta mDNS no solicitada
(RFC 6762 §8.3) con los mismos registros que publica avahi — PTR, SRV,
TXT (version=1 protocol=1 api=/api) y A — en cada interfaz IPv4 que no
sea de bucle local, cada una con su propia dirección. Un equipo que está
explorando la recibe dentro de su ventana de consulta, haya llegado o no
su pregunta. Los registros usan TTL de 120 s para que una dirección
obsoleta caduque rápido.

Complementa a avahi (que sigue respondiendo consultas); no lo sustituye.
Solo biblioteca estándar. Lo arranca y lo detiene `./run serve`.

Uso: announce.py --port 8000 [--name EcoStation] [--host jperez] [--interval 1]
"""
import argparse
import fcntl
import math
import socket
import struct
import sys
import time

SERVICE = "_ecostation._tcp.local"
MDNS_ADDR = ("224.0.0.251", 5353)
TXT = [b"version=1", b"protocol=1", b"api=/api"]
TTL = 120
CLASS_IN = 1
CACHE_FLUSH = 0x8000
T_A, T_PTR, T_TXT, T_SRV = 1, 12, 16, 33

MAX_DNS_LABEL_BYTES = 63
MAX_DNS_NAME_BYTES = 253


def validate_name(raw):
    """Nombre de instancia/servicio: etiquetas DNS-SD válidas (<=63 bytes)."""
    value = str(raw).strip()
    if not value:
        raise ValueError("el nombre no puede estar vacío")
    if len(value.encode("utf-8")) > MAX_DNS_NAME_BYTES:
        raise ValueError(f"el nombre supera {MAX_DNS_NAME_BYTES} bytes")
    for label in value.rstrip(".").split("."):
        encoded = label.encode("utf-8")
        if not encoded:
            raise ValueError("el nombre contiene una etiqueta vacía")
        if len(encoded) > MAX_DNS_LABEL_BYTES:
            raise ValueError(
                f"cada etiqueta del nombre debe medir máximo {MAX_DNS_LABEL_BYTES} bytes"
            )
    return value


def name_arg(raw):
    try:
        return validate_name(raw)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(str(exc)) from exc


def port_arg(raw):
    try:
        value = int(raw)
    except (TypeError, ValueError) as exc:
        raise argparse.ArgumentTypeError("el puerto debe ser un entero") from exc
    if not 1 <= value <= 65535:
        raise argparse.ArgumentTypeError("el puerto debe estar entre 1 y 65535")
    return value


def interval_arg(raw):
    try:
        value = float(raw)
    except (TypeError, ValueError) as exc:
        raise argparse.ArgumentTypeError("el intervalo debe ser un número") from exc
    if not math.isfinite(value) or value < 0:
        raise argparse.ArgumentTypeError("el intervalo no puede ser negativo")
    return value



def name(n):
    out = b""
    for label in n.rstrip(".").split("."):
        raw = label.encode("utf-8")
        out += bytes([len(raw)]) + raw
    return out + b"\x00"


def rr(owner, rtype, rdata, flush):
    cls = CLASS_IN | (CACHE_FLUSH if flush else 0)
    return name(owner) + struct.pack(">HHIH", rtype, cls, TTL, len(rdata)) + rdata


def packet(instance, host, port, ipv4):
    fqdn = f"{instance}.{SERVICE}"
    target = f"{host}.local"
    answers = [
        rr(SERVICE, T_PTR, name(fqdn), False),  # registro compartido: sin cache-flush
        rr(fqdn, T_SRV, struct.pack(">HHH", 0, 0, port) + name(target), True),
        rr(fqdn, T_TXT, b"".join(bytes([len(t)]) + t for t in TXT), True),
        rr(target, T_A, socket.inet_aton(ipv4), True),
    ]
    # id 0, flags QR|AA, 0 preguntas, N respuestas (RFC 6762 §18)
    return struct.pack(">HHHHHH", 0, 0x8400, 0, len(answers), 0, 0) + b"".join(answers)


def ipv4_interfaces():
    """(ifname, dirección) de cada interfaz IPv4 activa y no de bucle local."""
    out = []
    for _, ifname in socket.if_nameindex():
        if ifname == "lo":
            continue
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            flags = struct.unpack("H", fcntl.ioctl(s, 0x8913, struct.pack("256s", ifname.encode()[:15]))[16:18])[0]
            if not flags & 0x1 or flags & 0x8:  # IFF_UP, IFF_LOOPBACK
                continue
            addr = fcntl.ioctl(s, 0x8915, struct.pack("256s", ifname.encode()[:15]))[20:24]  # SIOCGIFADDR
            out.append((ifname, socket.inet_ntoa(addr)))
        except OSError:
            pass  # esa interfaz no tiene dirección IPv4
        finally:
            s.close()
    return out


def send(ipv4, payload):
    """Un datagrama desde un socket efímero en el puerto 5353.

    Los receptores DEBEN ignorar respuestas cuyo puerto de origen no sea
    5353 (RFC 6762 §6; ESP-IDF lo hace y se verificó en banco), así que
    el socket comparte el 5353 con avahi. Se cierra justo después de
    enviar: un socket que se quedara escuchando ahí podría recibir
    datagramas mDNS unicast destinados a avahi.
    """
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 255)  # RFC 6762 §11
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_LOOP, 0)  # nunca alimentar a nuestro propio avahi
    s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(ipv4))
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
    try:
        s.bind(("0.0.0.0", 5353))
        s.sendto(payload, MDNS_ADDR)
    finally:
        s.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=port_arg, required=True)
    ap.add_argument("--name", type=name_arg, default="EcoStation")
    ap.add_argument("--host", type=name_arg, default=socket.gethostname().split(".")[0])
    ap.add_argument("--interval", type=interval_arg, default=1.0)
    ap.add_argument("--once", action="store_true", help="una ronda y termina (diagnóstico)")
    args = ap.parse_args()

    last = None
    while True:
        ifaces = ipv4_interfaces()
        if ifaces != last:  # la red cambió (hotspot, DHCP): seguirla
            print(f"anunciando {args.name}.{SERVICE}:{args.port} en "
                  + (", ".join(f"{i}={a}" for i, a in ifaces) or "(sin interfaz IPv4)"), flush=True)
            last = ifaces
        for ifname, addr in ifaces:
            try:
                send(addr, packet(args.name, args.host, args.port, addr))
            except OSError as e:
                print(f"envío por {ifname} falló: {e}", file=sys.stderr, flush=True)
        if args.once:
            return
        time.sleep(args.interval)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
