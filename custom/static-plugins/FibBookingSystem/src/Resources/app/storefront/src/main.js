import FibBookingWidgetPlugin from './plugins/fib-booking-widget/fib-booking-widget.plugin';
import FibBookingCalendarPlugin from './plugins/fib-booking-calendar/fib-booking-calendar.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('FibBookingWidget', FibBookingWidgetPlugin, '[data-fib-booking-widget]');
PluginManager.register('FibBookingCalendar', FibBookingCalendarPlugin, '[data-fib-booking-calendar]');
