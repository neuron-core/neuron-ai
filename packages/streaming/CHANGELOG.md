# Changelog

## 0.2.0

- Introduce transport-neutral multi-segment reconciliation with `createChannelConsumer`, a thin `subscribeToPusher` input adapter, and a validated `createProtocolStream` output bridge.
- Preserve stream identity and sequence in reconstructed events; support plain callbacks, UI frameworks, AG-UI, and Vercel consumers without core SDK dependencies.
- Order segment events, reassemble base64url UTF-8 payloads, and handle terminal events after preceding data.
- Bound buffering and segment tracking; detect gaps and conflicting queued duplicates.
- Support encrypted channels through the official Pusher browser SDK.
