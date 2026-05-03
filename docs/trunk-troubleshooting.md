# Trunk Troubleshooting

## Symptoms
- Outbound calls fail with "All circuits busy" or silence
- Trunk shows "Unregistered" or "Unavailable"
- Inbound calls on a DID don't ring

## Registration Issues

### Trunk not registering
- Check: `diagnose trunk <id>` or `show trunk <id>`
- Verify credentials match the SIP provider's settings
- Check if the provider requires a specific transport (UDP/TCP/TLS)
- Check firewall: `show firewall` — provider IP must be accessible

### Trunk registered but calls fail
- Check: `call history` for recent failed calls
- Check outbound routes: `list outbound routes` — is the trunk assigned to a route?
- Check dial patterns: the outbound route must match the dialed number format

## Outbound Call Issues

### "All circuits busy"
- All trunks in the outbound route are unavailable
- Check: `list trunks` — are any disabled?
- Check: `diagnose trunk <id>` for each trunk

### Call connects but no audio
- NAT issue — see NAT Configuration guide
- Codec mismatch between PBX and provider
- Check: `endpoint details <trunk-name>` — verify codec list

### Wrong Caller ID
- Check: `show trunk <id>` — verify outbound CID settings
- Provider may override CID — check with your SIP provider

## Inbound Call Issues

### DID not ringing
- Check: `list inbound routes` — is the DID configured?
- Verify the DID number matches exactly (some providers send +1, some don't)
- Check destination: where is the inbound route pointed?

### Wrong destination
- Check: `show inbound route <DID>` — verify the destination
- May need to update: `add inbound route <DID> to <ext>`

## Diagnostic Commands
```
diagnose trunk <id>
show trunk <id>
list trunks
list outbound routes
list inbound routes
call history
registrations
```
