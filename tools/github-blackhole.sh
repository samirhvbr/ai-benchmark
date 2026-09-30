#!/bin/sh
# github-blackhole.sh: blackhole routes for GitHub on the LEB execution VM
# (README, Execution environment). Installed as /usr/local/sbin/github-blackhole.sh and run at
# boot by a oneshot systemd unit (ExecStart: `add`, ExecStop: `del`). The VM's copy runs the same
# commands; its comments are in Portuguese.
# Idempotent: "replace" lets it run any number of times. To remove: github-blackhole.sh del
#
# Section 1: the prefixes GitHub, Inc. (AS36459) announces in BGP.
# Section 2: GitHub's edges on Azure listed at https://api.github.com/meta
#            (categories web, api, git, packages), fetched on 2026-09-30.
#            They are individual GitHub /32s, not shared Azure blocks.
#            The API is unreachable once the block is in place: to refresh the list,
#            fetch the meta from another machine and regenerate it.

ACTION="${1:-add}"

# --- Section 1: BGP prefixes ---
V4="
192.30.252.0/22
185.199.108.0/22
143.55.64.0/20
140.82.112.0/20
"

V6="
2a0a:a440::/29
2620:112:3000::/44
2606:50c0::/32
"

# --- Section 2: Azure edges (api.github.com/meta, 2026-09-30) ---
V4_META="
4.208.26.193/32
4.208.26.196/31
4.208.26.198/32
4.208.26.200/32
4.225.11.194/32
4.225.11.196/32
4.225.11.199/32
4.225.11.200/31
4.228.31.144/31
4.228.31.149/32
4.228.31.150/32
4.228.31.152/32
4.237.22.32/32
4.237.22.34/32
4.237.22.36/32
4.237.22.38/32
4.237.22.40/32
4.249.131.163/32
4.249.131.164/32
4.249.131.166/31
20.26.156.210/31
20.26.156.213/32
20.26.156.214/31
20.27.177.113/32
20.27.177.116/30
20.29.134.17/32
20.29.134.18/31
20.29.134.22/31
20.87.245.0/31
20.87.245.2/32
20.87.245.4/32
20.87.245.6/32
20.175.192.146/31
20.175.192.149/32
20.175.192.150/32
20.199.39.227/32
20.199.39.228/32
20.199.39.231/32
20.199.39.232/32
20.200.245.241/32
20.200.245.244/31
20.200.245.247/32
20.200.245.248/32
20.201.28.144/32
20.201.28.148/32
20.201.28.151/32
20.201.28.152/32
20.205.243.160/31
20.205.243.164/32
20.205.243.166/32
20.205.243.168/32
20.207.73.81/32
20.207.73.82/31
20.207.73.85/32
20.207.73.86/32
20.217.135.0/31
20.217.135.4/31
20.233.83.145/32
20.233.83.146/31
20.233.83.148/31
48.202.248.34/32
48.202.248.38/31
48.202.248.40/32
48.204.201.2/32
48.204.201.5/32
48.204.201.6/32
48.204.201.9/32
172.182.252.130/32
172.182.252.133/32
172.182.252.135/32
172.182.252.136/31
"

V6_META="

"

for p in $V4 $V4_META; do
    if [ "$ACTION" = "del" ]; then
        ip -4 route del blackhole "$p" 2>/dev/null || true
    else
        ip -4 route replace blackhole "$p"
    fi
done

for p in $V6 $V6_META; do
    if [ "$ACTION" = "del" ]; then
        ip -6 route del blackhole "$p" 2>/dev/null || true
    else
        ip -6 route replace blackhole "$p"
    fi
done
