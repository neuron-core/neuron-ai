import { createChannelConsumer, subscribeToMercure, subscribeToPusher } from '@neuron-core/streaming';
import Pusher from 'pusher-js/with-encryption';

Object.assign(window, { createChannelConsumer, subscribeToMercure, subscribeToPusher, Pusher });
