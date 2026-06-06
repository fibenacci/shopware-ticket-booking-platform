import FibBookingWidgetPlugin from './plugins/fib-booking-widget/fib-booking-widget.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('FibBookingWidget', FibBookingWidgetPlugin, '[data-fib-booking-widget]');
