<?php

class ReceiptPdf
{
    private $content = '';

    public static function output($title, $receipt, $filename, $receiptType)
    {
        $pdf = new self();
        $pdf->drawReceipt($title, $receipt, $receiptType);
        $pdf->send($filename);
    }

    private function drawReceipt($title, $receipt, $receiptType)
    {
        $isCashReceipt = $receiptType === 'cash';
        $isReservationReceipt = $receiptType === 'reservation';
        $isDemoPayment = in_array($receipt['payment_method'], ['demo_online', 'paymongo_qrph', 'stripe', 'Demo online (no real payment)', 'Stripe test (no real payment)'], true);
        $isPaid = $isCashReceipt || $isReservationReceipt;
        $hasReservationFee = (float) $receipt['reservation_fee'] > 0;
        $this->content = '';
        $this->rectangle(0, 570, 432, 78, 0.02, 0.65, 0.83, true);
        $this->text(30, 619, 372, $receipt['business_name'], 17, true, 1, 1, 1);
        if (!empty($receipt['short_name'])) {
            $this->text(30, 598, 372, $receipt['short_name'], 10, false, 0.92, 0.97, 1);
        }
        $this->text(30, 578, 372, 'VEHICLE WASH AND CARE SERVICES', 8, true, 0.92, 0.97, 1);

        $this->text(30, 544, 240, strtoupper($title), 15, true);
        $this->text(300, 546, 102, 'DATE: ' . $receipt['document_date'], 9, true, 0.16, 0.22, 0.29);
        $this->text(30, 525, 372, 'BOOKING / RECEIPT NO.: ' . $receipt['code'], 9, true);
        $this->line(30, 513, 402, 513, 0.02, 0.65, 0.83, 2);

        $this->sectionHeader(30, 488, 178, 'CUSTOMER INFORMATION');
        $this->sectionHeader(224, 488, 178, 'VEHICLE INFORMATION');
        $this->detail(30, 468, 'Name', $receipt['client_name'], 178);
        $this->detail(30, 449, 'Contact', $receipt['contact'], 178);
        $this->detail(224, 468, 'Type', $receipt['vehicle'], 178);
        $this->detail(224, 449, 'Plate', $receipt['plate_number'], 178);
        $this->detail(30, 430, 'Appointment', $receipt['appointment'], 372);
        $this->line(30, 417, 402, 417, 0.72, 0.77, 0.81, 0.7);

        $this->text(30, 397, 200, 'SERVICE DETAILS', 10, true);
        $tableTop = 382;
        $headerHeight = 24;
        $serviceCount = count($receipt['services']);
        $rowHeight = $serviceCount <= 4 ? 32 : ($serviceCount <= 7 ? 26 : 20);
        $this->rectangle(30, $tableTop - $headerHeight, 372, $headerHeight, 0.02, 0.65, 0.83, true);
        $this->text(36, $tableTop - 16, 199, 'Description of Service', 9, true, 1, 1, 1);
        $this->text(244, $tableTop - 16, 28, 'Qty', 9, true, 1, 1, 1, 'center');
        $this->text(278, $tableTop - 16, 58, 'Cost', 9, true, 1, 1, 1, 'right');
        $this->text(340, $tableTop - 16, 56, 'Line Total', 9, true, 1, 1, 1, 'right');

        $rowY = $tableTop - $headerHeight;
        foreach ($receipt['services'] as $service) {
            $rowY -= $rowHeight;
            $this->rectangle(30, $rowY, 372, $rowHeight, 0.72, 0.77, 0.81, false);
            $textY = $rowY + ($rowHeight - 9) / 2;
            $this->text(36, $textY, 199, $service['name'], 9);
            $this->text(244, $textY, 28, '1', 9, false, 0.1, 0.1, 0.1, 'center');
            $this->text(278, $textY, 58, 'PHP ' . $service['formatted_price'], 9, false, 0.1, 0.1, 0.1, 'right');
            $this->text(340, $textY, 56, 'PHP ' . $service['formatted_price'], 9, false, 0.1, 0.1, 0.1, 'right');
        }
        $this->line(240, $tableTop, 240, $rowY, 0.72, 0.77, 0.81, 0.65);
        $this->line(274, $tableTop, 274, $rowY, 0.72, 0.77, 0.81, 0.65);
        $this->line(338, $tableTop, 338, $rowY, 0.72, 0.77, 0.81, 0.65);

        $summaryTop = $rowY - 15;
        $this->sectionHeader(30, $summaryTop, 178, 'PAYMENT RECORD');
        $this->text(36, $summaryTop - 20, 165, '[' . ($isPaid ? 'X' : ' ') . '] ' . $receipt['payment_method'], 9);
        $paymentStatusLabel = !$isPaid
            ? 'AWAITING PAYMENT'
            : ($receipt['payment_method'] === 'Stripe test (no real payment)'
                ? 'STRIPE TEST — NO REAL PAYMENT'
                : ($isDemoPayment ? 'DEMO RECORDED — NOT REAL' : 'PAID / VERIFIED'));
        $this->text(36, $summaryTop - 38, 165, 'Payment status: ' . $paymentStatusLabel, 8, true);
        if ($isReservationReceipt) {
            $this->text(36, $summaryTop - 55, 165, $isDemoPayment ? 'Booking reference:' : 'Transfer reference:', 8, true);
            $this->text(36, $summaryTop - 68, 165, $isDemoPayment ? $receipt['code'] : $receipt['transaction_reference'], 8);
        } elseif ($isCashReceipt && $receipt['reservation_fee_payment_status'] === 'paid') {
            $feeWasDemo = in_array($receipt['reservation_fee_payment_method'], ['demo_online', 'paymongo_qrph'], true);
            $this->text(36, $summaryTop - 55, 165, $feeWasDemo ? 'Reservation fee recorded as demo.' : 'Reservation fee paid separately.', 7);
        }

        $this->summaryRow(224, $summaryTop - 2, $hasReservationFee ? 'Services subtotal' : 'Services', $receipt['formatted_subtotal']);
        if ($hasReservationFee) {
            $this->summaryRow(224, $summaryTop - 23, 'Reservation fee', $receipt['formatted_reservation_fee']);
        }
        $totalOffset = $hasReservationFee ? 44 : 23;
        $this->summaryRow(224, $summaryTop - $totalOffset, 'Total', $receipt['formatted_total'], true);
        $this->summaryRow(224, $summaryTop - $totalOffset - 21, $isDemoPayment ? 'Demo amount' : 'Amount paid', $receipt['formatted_amount_paid'], true);
        if ($isReservationReceipt) {
            $this->summaryRow(224, $summaryTop - 86, 'Balance due', $receipt['formatted_cash_balance']);
        } elseif ($isCashReceipt && $receipt['reservation_fee_payment_status'] === 'paid') {
            $this->summaryRow(224, $summaryTop - 86, 'Reservation fee paid', $receipt['formatted_reservation_fee']);
        }

        $footerY = max(20, $summaryTop - 112);
        $this->line(30, $footerY + 13, 402, $footerY + 13, 0.72, 0.77, 0.81, 0.8);
        $footer = $isDemoPayment && $isPaid
            ? 'Project demo only — no real payment was made.'
            : ($isCashReceipt
                ? 'Thank you for choosing ' . $receipt['business_name'] . '!'
                : ($isReservationReceipt
                    ? 'Reservation fee verified. The remaining balance is payable at the shop.'
                    : 'Booking confirmation only. Pay the amount due at the shop.'));
        $footerLines = explode("\n", wordwrap($footer, 72, "\n", true));
        foreach ($footerLines as $index => $footerLine) {
            $this->text(
                30,
                $footerY - ($index * 10),
                372,
                $footerLine,
                8,
                false,
                0.25,
                0.3,
                0.35,
                'center'
            );
        }
    }

    private function sectionHeader($x, $y, $width, $label)
    {
        $this->rectangle($x, $y - 15, $width, 19, 0.88, 0.94, 0.96, true);
        $this->text($x + 6, $y - 9, $width - 12, $label, 8, true, 0.08, 0.37, 0.48);
    }

    private function detail($x, $y, $label, $value, $width)
    {
        $this->text($x, $y, 55, $label . ':', 8, true, 0.32, 0.36, 0.4);
        $this->text($x + 58, $y, $width - 58, $value, 8);
    }

    private function summaryRow($x, $y, $label, $amount, $bold = false)
    {
        $this->line($x, $y - 7, 402, $y - 7, 0.72, 0.77, 0.81, 0.6);
        $this->text($x + 6, $y, 82, $label, 8, $bold);
        $this->text($x + 87, $y, 85, 'PHP ' . $amount, 8, $bold, 0.1, 0.1, 0.1, 'right');
    }

    private function text($x, $y, $width, $text, $size = 9, $bold = false, $r = 0.12, $g = 0.15, $b = 0.18, $align = 'left')
    {
        $text = self::toPdfText($text);
        $font = $bold ? 'F2' : 'F1';
        $charWidth = $size * ($bold ? 0.57 : 0.53);
        $textWidth = strlen($text) * $charWidth;
        if ($align === 'center') {
            $x += max(0, ($width - $textWidth) / 2);
        } elseif ($align === 'right') {
            $x += max(0, $width - $textWidth);
        }
        $this->content .= sprintf(
            "%.3f %.3f %.3f rg BT /%s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $r,
            $g,
            $b,
            $font,
            $size,
            $x,
            $y,
            self::escape($text)
        );
    }

    private function line($x1, $y1, $x2, $y2, $r = 0, $g = 0, $b = 0, $width = 1)
    {
        $this->content .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $r,
            $g,
            $b,
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    private function rectangle($x, $y, $width, $height, $r, $g, $b, $fill)
    {
        $operator = $fill ? 'f' : 'S';
        if (!$fill) {
            $this->content .= sprintf("%.3f %.3f %.3f RG 0.65 w ", $r, $g, $b);
        } else {
            $this->content .= sprintf("%.3f %.3f %.3f rg ", $r, $g, $b);
        }
        $this->content .= sprintf("%.2f %.2f %.2f %.2f re %s\n", $x, $y, $width, $height, $operator);
    }

    private function send($filename)
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 432 648] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($this->content) . " >>\nstream\n" . $this->content . "endstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    private static function toPdfText($text)
    {
        $text = (string) $text;
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $text);
    }

    private static function escape($text)
    {
        return str_replace(["\\", "(", ")", "\r"], ["\\\\", "\\(", "\\)", ""], $text);
    }
}
