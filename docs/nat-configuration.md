# NAT Configuration

## Overview
When a PBX is behind NAT (Network Address Translation), SIP and RTP traffic need special handling to work correctly. Most one-way audio and registration issues trace back to NAT misconfiguration.

## Key Settings

### External IP
The public IP address that remote phones and trunks use to reach the PBX.
- Check: `show sip settings` or `external ip`
- Set: `set external ip to <IP>`
- Must match your actual public IP — check with `external ip`

### Local Networks
IP ranges that should NOT use the external IP (they're on the local network).
- Typically: `192.168.0.0/16`, `10.0.0.0/8`, `172.16.0.0/12`
- Check: `show sip settings`

### PJSIP Endpoint NAT Settings
Each endpoint has NAT-related settings:
- `rtp_symmetric=yes` — send RTP back to where it came from
- `force_rport=yes` — use the port the request came from
- `rewrite_contact=yes` — rewrite the Contact header with the actual IP
- Check: `endpoint details <ext>`

## Common Problems

### One-way audio
- Cause: RTP going to the wrong address
- Fix: Enable `rtp_symmetric` and set correct external IP and local networks

### Phone registers but can't make/receive calls
- Cause: External IP not set, or local networks not defined
- Fix: Set external IP and local networks in SIP Settings

### Intermittent registration drops
- Cause: NAT timeout — firewall closes the connection
- Fix: Reduce registration expiry time on the phone (60-120 seconds), or enable keep-alives

## Diagnostic Commands
```
show sip settings
external ip
endpoint details <ext>
diagnose ext <ext>
show firewall
```
