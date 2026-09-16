import { createChannelConsumer, subscribeToPusher } from '@neuron-core/streaming';
import Pusher from 'pusher-js/with-encryption';

Object.assign(window, { createChannelConsumer, subscribeToPusher, Pusher });
