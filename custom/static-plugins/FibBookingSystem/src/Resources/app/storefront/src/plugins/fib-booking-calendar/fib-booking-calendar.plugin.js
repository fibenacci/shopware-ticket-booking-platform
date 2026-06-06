import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Booking calendar ("Terminkalender") — rendered by the CMS element
 * cms-element-fib-booking-calendar.
 *
 * Month grid over the resource's operator-defined slots:
 *   day status "free"    → green, selectable
 *   day status "partial" → amber, selectable
 *   day status "full"    → RED, disabled (sold out)
 *   no slots             → muted, disabled
 *
 * Booking flow: pick day → pick slot → pick package (product) + quantity →
 * hold is created → product goes to the cart → redirect to checkout.
 */
export default class FibBookingCalendarPlugin extends Plugin {
    static options = {
        resourceId: null,
        resourceName: null,
        packages: [],
        monthsAhead: 3,
        calendarUrl: null,
        holdUrl: null,
        cartUrl: null,
        checkoutUrl: null,
    };

    init() {
        if (!this.options.resourceId || !this.options.calendarUrl) {
            return;
        }

        this._client = new HttpClient();
        this._today = new Date();
        this._current = new Date(this._today.getFullYear(), this._today.getMonth(), 1);
        this._maxMonth = new Date(this._today.getFullYear(), this._today.getMonth() + Math.max(0, (this.options.monthsAhead || 3) - 1), 1);
        this._days = new Map();
        this._selectedSlot = null;

        this._grid = this.el.querySelector('.fib-booking-calendar-grid');
        this._monthLabel = this.el.querySelector('.fib-booking-calendar-month');
        this._slotsContainer = this.el.querySelector('.fib-booking-calendar-slots');
        this._slotList = this.el.querySelector('.fib-booking-calendar-slot-list');
        this._actions = this.el.querySelector('.fib-booking-calendar-actions');
        this._error = this.el.querySelector('.fib-booking-calendar-error');

        this.el.querySelector('.fib-booking-calendar-prev')?.addEventListener('click', () => this._navigate(-1));
        this.el.querySelector('.fib-booking-calendar-next')?.addEventListener('click', () => this._navigate(1));
        this.el.querySelector('.fib-booking-calendar-book')?.addEventListener('click', this._onBook.bind(this));

        this._loadMonth();
    }

    _navigate(direction) {
        const target = new Date(this._current.getFullYear(), this._current.getMonth() + direction, 1);
        const min = new Date(this._today.getFullYear(), this._today.getMonth(), 1);

        if (target < min || target > this._maxMonth) {
            return;
        }

        this._current = target;
        this._loadMonth();
    }

    _monthKey() {
        return `${this._current.getFullYear()}-${String(this._current.getMonth() + 1).padStart(2, '0')}`;
    }

    _loadMonth() {
        this._hideError();
        this._selectSlot(null);
        this._monthLabel.textContent = this._current.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

        this._client.post(this.options.calendarUrl, JSON.stringify({
            resourceId: this.options.resourceId,
            month: this._monthKey(),
        }), (response) => this._onMonthLoaded(response));
    }

    _onMonthLoaded(response) {
        let data;
        try {
            data = JSON.parse(response);
        } catch (error) {
            this._showError('Could not load the calendar.');
            return;
        }

        if (data.error) {
            this._showError(data.error);
            return;
        }

        this._days = new Map((data.days || []).map((day) => [day.date, day]));
        this._renderGrid();
    }

    _renderGrid() {
        this._grid.innerHTML = '';

        const year = this._current.getFullYear();
        const month = this._current.getMonth();
        const firstWeekday = (new Date(year, month, 1).getDay() + 6) % 7; // Monday first
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        for (let i = 0; i < firstWeekday; i++) {
            this._grid.appendChild(this._cell(''));
        }

        for (let dayNumber = 1; dayNumber <= daysInMonth; dayNumber++) {
            const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`;
            const day = this._days.get(dateKey);
            const status = day ? day.status : 'none';

            const cell = this._cell(String(dayNumber));
            cell.classList.add(`fib-booking-day--${status}`);
            cell.setAttribute('role', 'gridcell');

            if (day && status !== 'full') {
                cell.classList.add('fib-booking-day--selectable');
                cell.addEventListener('click', () => this._onDaySelected(cell, day));
            }

            this._grid.appendChild(cell);
        }
    }

    _cell(text) {
        const cell = document.createElement('div');
        cell.className = 'fib-booking-day';
        cell.textContent = text;
        return cell;
    }

    _onDaySelected(cell, day) {
        this._grid.querySelectorAll('.fib-booking-day--selected').forEach((el) => el.classList.remove('fib-booking-day--selected'));
        cell.classList.add('fib-booking-day--selected');
        this._selectSlot(null);

        this._slotList.innerHTML = '';
        day.slots.forEach((slot) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-primary fib-booking-slot';
            button.disabled = slot.available < 1;
            const from = new Date(slot.startsAt);
            const to = new Date(slot.endsAt);
            button.textContent = `${from.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })} – ${to.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })} (${slot.available})`;

            button.addEventListener('click', () => {
                this._slotList.querySelectorAll('.active').forEach((el) => el.classList.remove('active'));
                button.classList.add('active');
                this._selectSlot(slot);
            });

            this._slotList.appendChild(button);
        });

        this._slotsContainer.classList.remove('d-none');
    }

    _selectSlot(slot) {
        this._selectedSlot = slot;
        this._actions?.classList.toggle('d-none', !slot);
        if (!slot) {
            this._slotsContainer?.classList.add('d-none');
        }
    }

    _onBook() {
        if (!this._selectedSlot) {
            return;
        }

        this._hideError();

        const packageSelect = this.el.querySelector('.fib-booking-calendar-package');
        const productId = packageSelect ? packageSelect.value : null;
        const quantityInput = this.el.querySelector('.fib-booking-calendar-quantity');
        const quantity = Math.max(1, parseInt(quantityInput?.value ?? '1', 10) || 1);

        if (!productId) {
            this._showError('No bookable package is configured.');
            return;
        }

        this._client.post(this.options.holdUrl, JSON.stringify({
            resourceId: this.options.resourceId,
            startsAt: this._selectedSlot.startsAt,
            endsAt: this._selectedSlot.endsAt,
            quantity,
        }), (holdResponse) => {
            let hold;
            try {
                hold = JSON.parse(holdResponse);
            } catch (error) {
                this._showError('Booking failed.');
                return;
            }

            if (!hold.id || !hold.token) {
                this._showError(hold.error || 'The selected slot is no longer available.');
                this._loadMonth();
                return;
            }

            this._client.post(this.options.cartUrl, JSON.stringify({
                productId,
                holdId: hold.id,
                holdToken: hold.token,
                quantity,
            }), (cartResponse) => {
                let cart;
                try {
                    cart = JSON.parse(cartResponse);
                } catch (error) {
                    this._showError('Booking failed.');
                    return;
                }

                if (!cart.success) {
                    this._showError(cart.error || 'Booking failed.');
                    return;
                }

                window.location.assign(this.options.checkoutUrl || '/checkout/cart');
            });
        });
    }

    _showError(message) {
        if (this._error) {
            this._error.textContent = message;
            this._error.classList.remove('d-none');
        }
    }

    _hideError() {
        this._error?.classList.add('d-none');
    }
}
