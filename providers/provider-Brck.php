<?php

namespace FreePBX\modules\Smsconnector\Provider;

class Brck extends providerBase
{
    public function __construct()
    {
        parent::__construct();
        $this->name       = _('BRCK');
        $this->nameRaw    = 'brck';
        $this->APIUrlInfo = 'https://portal.brck.com/api/v1/docs';
        $this->APIVersion = 'v1.0';

        $this->configInfo = array(
            'bearer_token' => array(
                'type'      => 'string',
                'label'     => _('Bearer Token'),
                'help'      => _("Enter the BRCK Bearer Token"),
                'default'   => '',
                'required'  => true,
                'placeholder' => _('Enter Bearer Token'),
            ),
        );
    }

    public function sendMedia($id, $to, $from, $message = null)
    {
        $req = array(
            'to'    => array($to),
            'from'  => $from,
            'media' => $this->media_urls($id)
        );
        if ($message) {
            $attr['text'] = $message;
        }

        $this->sendBrck($req, $id);
        return true;
    }

    public function sendMessage($id, $to, $from, $message = null)
    {
        $req = array(
            'to'    => array($to),
            'from'  => $from,
            'text'  => $message
        );
        $this->sendBrck($req, $id);
        return true;
    }

    private function sendBrck($payload, $mid)
    {
        $config = $this->getConfig($this->nameRaw);

        $headers = array(
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $config['bearer_token']
        );
        $url = "https://api.brck.com/api/v1/callbacks/outbound/messaging";
        $json = json_encode($payload);
        $session = \FreePBX::Curl()->requests($url);

        try {
            $brckResponse = $session->post('', $headers, $json, array());
            freepbx_log(FPBX_LOG_INFO, sprintf(_('%s responds: HTTP %s, %s'), $this->nameRaw, $brckResponse->status_code, $brckResponse->body), true);

            if (!$brckResponse->success) {
                throw new \Exception(sprintf(_('HTTP %s, %s'), $brckResponse->status_code, $brckResponse->body));
            }
            $this->setDelivered($mid);
        } catch (\Exception $e) {
            throw new \Exception(_('Unable to send message: ') . $e->getMessage());
        }
    }

    public function callPublic($connector)
    {
        $config = $this->getConfig($this->nameRaw);

        if ($_SERVER['REQUEST_METHOD'] !== "POST") {
            return 405;
        }

        $postdata = file_get_contents("php://input");
        $sms      = json_decode($postdata)[0];

        freepbx_log(FPBX_LOG_INFO, sprintf(_('Webhook (%s) in: %s'), $this->nameRaw, print_r($postdata, true)));

        if (empty($sms)) {
            return 403;
        }

        if (isset($sms)) {
            if (isset($sms->type) && ($sms->type == 'message-received')) {

                $from = ltrim($sms->message->from, '+'); // strip + if exists
                $to   = ltrim($sms->to, '+'); // The sms->to will always just be one number, but if we wanted to support group messaging we'd need to look at sms->message->to which holds an array of all numbers in the convo
                $text = $sms->message->text;
                $emid = $sms->message->id;

                try {
                    $msgid = $connector->getMessage($to, $from, '', $text, null, null, $emid);
                } catch (\Exception $e) {
                    throw new \Exception(sprintf(_('Unable to get message: %s'), $e->getMessage()));
                }

                if (isset($sms->message->media[0])) {
                    // Create authentication header/context required to grab media from Bandwidth
                    $context = stream_context_create([
                        "http" => [
                            "header" => "Authorization: Basic " . base64_encode($config['api_token'] . ":" . $config['api_secret'])
                        ]
                    ]);

                    foreach ($sms->message->media as $media) {
                        $img = file_get_contents($media, false, $context);
                        $purl = parse_url($media);
                        $name = $msgid . basename($purl['path']);

                        try {
                            $connector->addMedia($msgid, $name, $img);
                        } catch (\Exception $e) {
                            throw new \Exception(sprintf(_('Unable to store MMS media: %s'), $e->getMessage()));
                        }
                    }
                }

                $connector->emitSmsInboundUserEvt($msgid, $to, $from, '', $text, null, 'Smsconnector', $emid);
            } else if (isset($sms->type)) {
                // Likely a callback for delivery status
                // See https://dev.bandwidth.com/docs/messaging/webhooks/
                // These can be disabled when setting up the application within Bandwidth
                // It would be good to support these in the future
                // We still reply with a 202 to appease Bandwidth
                freepbx_log(FPBX_LOG_INFO, sprintf(_('Incoming message of unknown type %s. Contents: %s'), $sms->type, print_r($sms, true)));
            }
        }

        return 202;
    }
}
