# SIP Registration Failures

## Symptoms
- Extension shows as "Unavailable" or "Offline"
- Phone displays "Registration Failed" or "403 Forbidden"
- `diagnose ext` shows no contacts

## Common Causes

### Wrong credentials
- Secret/password mismatch between FreePBX and the phone
- Check: `show extension <ext>` to see the configured secret
- Fix: re-enter the secret on the phone, or update in FreePBX

### NAT/Firewall issues
- Phone is behind NAT and can't reach the PBX
- PBX is behind NAT and `external IP` is not set correctly
- Check: `show sip settings` — verify external IP and local networks
- Check: `show firewall` — ensure the phone's network is in a trusted zone
- Fix: Set external IP in SIP Settings, add local network ranges

### Transport mismatch
- Phone is using UDP but PBX only has TCP/TLS configured (or vice versa)
- Check: `endpoint details <ext>` — look at transport setting
- Fix: Match transport on phone to what PBX is listening on

### Max contacts reached
- The AOR (Address of Record) only allows 1 contact, and another device is registered
- Check: `endpoint details <ext>` — look at max_contacts in AOR section
- Fix: Increase max_contacts if multiple devices per extension are needed

### DNS resolution failure
- Phone can't resolve the PBX hostname
- Fix: Use IP address instead of hostname on the phone

## Diagnostic Commands
```
diagnose ext <number>
endpoint details <number>
ping <number>
show sip settings
show firewall
registrations
```
