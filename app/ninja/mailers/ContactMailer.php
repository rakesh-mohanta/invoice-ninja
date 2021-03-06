<?php namespace ninja\mailers;

use Invoice;
use Payment;
use Contact;
use Invitation;
use Activity;
use Utils;

class ContactMailer extends Mailer
{
    public function sendInvoice(Invoice $invoice)
    {
        $invoice->load('invitations', 'client', 'account');
        $entityType = $invoice->getEntityType();

        $view = 'invoice';
        $subject = trans("texts.{$entityType}_subject", ['invoice' => $invoice->invoice_number, 'account' => $invoice->account->getDisplayName()]);
        $accountName = $invoice->account->getDisplayName();

        foreach ($invoice->invitations as $invitation) {
            if (!$invitation->user || !$invitation->user->email) {
                return false;
            }
            if (!$invitation->contact || $invitation->contact->email) {
                return false;
            }

            $invitation->sent_date = \Carbon::now()->toDateTimeString();
            $invitation->save();

            $data = [
                'entityType' => $entityType,
                'link' => $invitation->getLink(),
                'clientName' => $invoice->client->getDisplayName(),
                'accountName' => $accountName,
                'contactName'    => $invitation->contact->getDisplayName(),
                'invoiceAmount' => Utils::formatMoney($invoice->amount, $invoice->client->currency_id),
                'emailFooter' => $invoice->account->email_footer,
                'showNinjaFooter' => !$invoice->account->isPro(),
            ];

            $fromEmail = $invitation->user->email;
            $this->sendTo($invitation->contact->email, $fromEmail, $accountName, $subject, $view, $data);

            Activity::emailInvoice($invitation);
        }

        if (!$invoice->isSent()) {
            $invoice->invoice_status_id = INVOICE_STATUS_SENT;
            $invoice->save();
        }

        \Event::fire('invoice.sent', $invoice);
    }

    public function sendPaymentConfirmation(Payment $payment)
    {
        $view = 'payment_confirmation';
        $subject = trans('texts.payment_subject', ['invoice' => $payment->invoice->invoice_number]);
        $accountName = $payment->account->getDisplayName();

        $data = [
            'accountName' => $accountName,
            'clientName' => $payment->client->getDisplayName(),
            'emailFooter' => $payment->account->email_footer,
            'paymentAmount' => Utils::formatMoney($payment->amount, $payment->client->currency_id),
            'showNinjaFooter' => !$payment->account->isPro(),
        ];

        $user = $payment->invitation->user;
        $this->sendTo($payment->contact->email, $user->email, $accountName, $subject, $view, $data);
    }

    public function sendLicensePaymentConfirmation($name, $email, $amount, $license, $productId)
    {
        $view = 'payment_confirmation';
        $subject = trans('texts.payment_subject');

        if ($productId == PRODUCT_ONE_CLICK_INSTALL) {
            $message = "Softaculous install license: $license";
        } elseif ($productId == PRODUCT_INVOICE_DESIGNS) {
            $message = "Invoice designs license: $license";
        } elseif ($productId == PRODUCT_WHITE_LABEL) {
            $message = "White label license: $license";
        }

        $data = [
            'accountName' => trans('texts.email_from'),
            'clientName' => $name,
            'emailFooter' => false,
            'paymentAmount' => Utils::formatMoney($amount, 1),
            'showNinjaFooter' => false,
            'emailMessage' => $message,
        ];

        $this->sendTo($email, CONTACT_EMAIL, CONTACT_NAME, $subject, $view, $data);
    }
}
