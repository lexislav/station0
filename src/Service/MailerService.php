<?php

declare(strict_types=1);

namespace Station0\Service;

use PHPMailer\PHPMailer\PHPMailer;

final class MailerService
{
    public function __construct(private readonly array $config) {}

    private function isSmtpConfigured(): bool
    {
        return $this->config['host'] !== 'localhost' || $this->config['username'] !== '';
    }

    public function send(string $to, string $subject, string $body): void
    {
        $mail = new PHPMailer(true);

        if ($this->isSmtpConfigured()) {
            $mail->isSMTP();
            $mail->Host       = $this->config['host'];
            $mail->Port       = $this->config['port'];
            $mail->SMTPAuth   = $this->config['username'] !== '';
            $mail->Username   = $this->config['username'];
            $mail->Password   = $this->config['password'];
            $mail->SMTPSecure = $this->config['encryption'];
        } else {
            $mail->isMail();
        }

        $mail->setFrom($this->config['from'], $this->config['fromName']);
        $mail->addAddress($to);
        $mail->CharSet  = 'UTF-8';
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $body;
        $mail->AltBody  = strip_tags($body);

        $mail->send();
    }
}
