# Firewall & Security

## Checking Firewall Status
```
show firewall
```
Shows intrusion detection status and network zones.

## Network Zones
FreePBX firewall uses zones to control access:
- **Internal** — full access, trusted LAN
- **Trusted** — external IPs that should have full access
- **External** — public internet, limited access
- **Other** — custom zones

### Add a network to a zone
```
add 10.0.0.0/8 to zone trusted
```

## Intrusion Detection (Fail2Ban)
Monitors for brute-force attacks and blocks offending IPs.
- Check status: `show firewall`
- If stopped, it may need to be started from the FreePBX GUI

## Common Security Issues

### SIP brute-force attacks
- Symptoms: high CPU, many failed registrations in logs
- Intrusion detection should catch these automatically
- Check: `show firewall` — is intrusion detection running?
- Ensure SIP port (5060) is not wide open to the internet

### Unauthorized calls
- Someone registered with stolen credentials
- Check: `extension states` — look for unexpected registrations
- Check: `call history` — look for unusual destinations
- Fix: Change the extension secret, enable firewall

### Web GUI exposed to internet
- The FreePBX GUI should not be directly accessible from the internet
- Use VPN or restrict to trusted networks
- Check: `show firewall` — verify zones

## Best Practices
- Always enable the firewall
- Use strong extension secrets (Frogman auto-generates them)
- Keep FreePBX and modules updated: `list notifications` for update alerts
- Monitor the audit log: `audit 10`
- Regular security scan: `validate`

## Diagnostic Commands
```
show firewall
list notifications
extension states
call history
audit 10
validate
```
