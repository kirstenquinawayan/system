# Offline cashier payment

The full booking amount, including the PHP 250.00 reservation fee, can be paid at the shop cashier. The booking confirmation lets customers select cash payment at the shop; this choice does not mark the booking as paid. New bookings start unpaid and remain unpaid until an administrator or staff member records a cashier payment, unless the optional Stripe test checkout described below is used. Paid bookings can then progress through the normal appointment workflow.

Cashier payment remains available by default. When `STRIPE_MODE=test` and a Stripe test secret key are configured, a booking can instead be paid in full through Stripe Checkout test mode. Test payments do not move funds, live Stripe keys are rejected, and the Stripe webhook confirms paid bookings. When staff record a cash payment, any open Stripe checkout is expired first; if Stripe has already confirmed payment or its status cannot be verified, the system will not record cash. Customer-submitted GCash/Maya transfer references remain disabled; the former manual online-payment endpoint rejects new transfer submissions. Historical online-payment records and staff review tools remain for existing records.

After submitting a booking, the customer can open the booking confirmation PDF. A payment receipt is available for paid bookings. Historical demo-payment records remain labeled as demo in administrative reports and receipts.

Paid bookings enter **In Progress** in appointment order when their scheduled time arrives and the previous active booking is **Completed**. Customer-facing service estimates are ranges: Car Wash 30-45 minutes, Engine Wash 30-60 minutes, Underwash 30-45 minutes, Undercoat 1-3 hours, Car Detailing 2-5 hours, Ceramic Coating 3-8 hours, and Car Painting 1-3 days. The maximum of each range (45, 60, 45, 180, 300, 480, and 4,320 minutes respectively) is used for automatic completion. A booking's duration is the sum of its selected services and is saved with the booking. Administrators can adjust auto-completion durations in Services, up to 3 days per service. The automatic check runs while the dashboard or booking list is open; staff do not manually select **In Progress**. Unpaid bookings do not start.

Appointment slots are offered every hour during shop hours: Monday-Friday from 7:00 AM until 11:00 PM, and Saturday-Sunday from 7:00 AM until 12:00 AM midnight at the end of the selected day. The last appointment starts are 10:00 PM on weekdays and 11:00 PM on weekends. Slots start at 7:00 AM and continue on the hour. Each appointment time accepts up to two non-cancelled bookings. The application serializes booking attempts for the same slot to enforce this limit.

Customers receive their booking reference and confirmation after submitting a booking. The public **Check Booking** lookup is not available.

Administrators and staff can use **Reports** to filter by booking date (default, to find recently submitted reservations) or appointment date (for schedule planning), then view/print a booking and payment report or export it as CSV.

Existing installations do not need a database migration to use offline cashier payment. Stripe test checkout uses the existing `booking_online_payments` table; confirm the table is present before enabling it. Legacy transfer-reference columns and staff review tools remain for historical records. The SMS verification-code table is not required.

For a free Render demo with Stripe test mode, see [DEPLOYMENT_RENDER.md](./DEPLOYMENT_RENDER.md).

For existing installations, apply `database/add_booking_time_and_reservation_fee.sql` once only if the existing database lacks its legacy columns. Apply `database/add_booking_payment_received_by.sql` once to enable payment recorder history and `database/add_booking_receipt_pdf_token.sql` once if the receipt token migration has not already been applied.

For existing installations, also apply `database/add_booking_schedule_index.sql` once to add the appointment availability index, `database/add_service_durations_and_auto_completion.sql` once to enable service durations and automatic completion, and `database/update_service_durations_to_max_estimates.sql` once to set the provided service estimates and auto-completion maximums. See `DEFENSE_GUIDE.md` for the system flow, database overview, and manual acceptance-test checklist.
