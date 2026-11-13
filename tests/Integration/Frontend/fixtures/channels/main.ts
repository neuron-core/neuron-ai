import { createChannelConsumer, subscribeToIQ, subscribeToMercure, subscribeToPusher } from '@neuron-core/streaming';
import Pusher from 'pusher-js/with-encryption';

Object.assign(window, { createChannelConsumer, subscribeToIQ, subscribeToMercure, subscribeToPusher, Pusher });
