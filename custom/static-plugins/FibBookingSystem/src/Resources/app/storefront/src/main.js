import FibBookingCalendarPlugin from './plugins/fib-booking-calendar/fib-booking-calendar.plugin';
import FibRotatingQrPlugin from './plugins/fib-rotating-qr/fib-rotating-qr.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('FibBookingCalendar', FibBookingCalendarPlugin, '[data-fib-booking-calendar]');
PluginManager.register('FibRotatingQr', FibRotatingQrPlugin, '[data-fib-rotating-qr]');
