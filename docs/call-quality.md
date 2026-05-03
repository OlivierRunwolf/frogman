# Call Quality Issues

## Symptoms
- Choppy or garbled audio
- One-way audio (can hear but not be heard, or vice versa)
- Echo on calls
- Calls dropping mid-conversation

## One-Way Audio

### Most common cause: NAT
- See NAT Configuration guide
- Quick check: `show sip settings` — is external IP set?
- Quick check: `endpoint details <ext>` — is rtp_symmetric enabled?

### Firewall blocking RTP
- RTP uses a range of UDP ports (default 10000-20000)
- Check: `show sip settings` — verify RTP range
- Ensure firewall allows UDP traffic on the RTP port range

## Choppy Audio

### Network issues
- Packet loss, jitter, or high latency on the network
- Check: `ping <ext>` — qualify the endpoint
- High RTT (round-trip time) indicates network problems

### Codec selection
- Use a codec appropriate for your bandwidth
- G.722 — wideband, good quality, moderate bandwidth
- G.711 (ulaw/alaw) — standard quality, higher bandwidth
- G.729 — low bandwidth, requires license
- Check: `endpoint details <ext>` — see allowed codecs

### CPU overload
- Transcoding between codecs uses CPU
- Check: `asterisk info` — system load
- Fix: Use the same codec on both ends to avoid transcoding

## Echo

- Usually caused by the phone hardware, not the PBX
- Check if echo cancellation is enabled on the phone
- Acoustic echo: speaker audio being picked up by the microphone

## Dropped Calls

### RTP timeout
- PBX drops the call if no RTP is received for 30 seconds
- Cause: NAT timeout, network outage, or phone went to sleep
- Check: `endpoint details <ext>` — rtp_timeout setting

### Session timers
- SIP session timers can expire if not refreshed
- Check: `endpoint details <ext>` — timers and timers_sess_expires

## Diagnostic Commands
```
diagnose ext <ext>
endpoint details <ext>
ping <ext>
show sip settings
asterisk info
call history
```
