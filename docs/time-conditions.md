# Time Conditions & Business Hours

## How Time Conditions Work
A time condition checks the current time against a schedule and routes calls differently:
- **Matched (true)** — during business hours → route to ring group, IVR, etc.
- **Not matched (false)** — after hours → route to voicemail, announcement, etc.

## Managing Time Conditions

### View all
```
list time conditions
```

### Toggle override
```
toggle time condition <id>
```
This forces the time condition to the "false" (after hours) state regardless of the actual time. Useful for holidays or early closures.

### Create a time route via dialplan
```
create time route for 1001 business hours to 600 after hours to voicemail
```

## Day/Night Controls

Similar to time conditions but simpler — a manual toggle:
```
list call flows
toggle daynight <id>
set daynight <id> to night
set daynight <id> to day
```

## Common Scenarios

### Holiday hours
1. Toggle the time condition to override: `toggle time condition <id>`
2. Calls go to after-hours destination
3. Toggle back when the holiday is over

### Temporary closure
1. Set day/night to night: `set daynight <id> to night`
2. When reopening: `set daynight <id> to day`

## Troubleshooting

### "Bad destinations" on time conditions
- The true or false destination points to something deleted
- Check: `list notifications` — look for BADDEST
- Fix: Reconfigure the time condition's destinations in FreePBX GUI

### Time condition not switching
- Check the server's timezone: `asterisk info`
- Verify the time group schedule matches your expectations

## Diagnostic Commands
```
list time conditions
list call flows
list notifications
asterisk info
```
