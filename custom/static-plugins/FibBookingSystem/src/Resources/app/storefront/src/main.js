import FibBookingCalendarPlugin from './plugins/fib-booking-calendar/fib-booking-calendar.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('FibBookingCalendar', FibBookingCalendarPlugin, '[data-fib-booking-calendar]');
