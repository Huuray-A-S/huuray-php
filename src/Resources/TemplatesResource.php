<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\HuurayException;
use Huuray\Exception\NotFoundException;
use Huuray\Internal\Wire;
use Huuray\Result\ListTemplatesResult;
use Huuray\Result\PdfTemplate;
use Huuray\Result\Template;

class TemplatesResource extends AbstractResource
{
    /**
     * Lists the delivery templates available to your account.
     *
     * `POST /v4/Template`
     *
     * Email and SMS delivery templates are in `templates`; PDF templates, which
     * attach the codes as a document to an email, are in `pdfTemplates`.
     *
     * The endpoint declares no request body in the API specification, so this
     * client sends none — confirmed accepted by the live API.
     *
     * Note: handle two outcomes, both observed live. When the account had **no
     * templates**, the API answered `404` ("There were no active templates"),
     * which this method throws as NotFoundException. An account with PDF
     * templates but no email or SMS templates got `200` instead, so `templates`
     * can be an empty array while `pdfTemplates` is not.
     *
     * @throws NotFoundException observed when the account had no templates
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII
     * @throws HuurayException
     */
    public function list(): ListTemplatesResult
    {
        // No body argument: POST /v4/Template is sent with no body at all.
        $data = $this->client->send('POST', '/v4/Template', retryable: true)->data;

        $templates = [];
        foreach (Wire::rows($data, 'Templates') as $row) {
            $templates[] = new Template(
                id: Wire::int($row, 'Id') ?? 0,
                name: Wire::string($row, 'Name'),
                type: Wire::string($row, 'Type'),
                language: Wire::string($row, 'Language'),
                sender: Wire::string($row, 'Sender'),
                subject: Wire::string($row, 'Subject'),
                formattedText: Wire::string($row, 'FormattedText'),
                plainText: Wire::string($row, 'PlainText'),
            );
        }

        $pdfTemplates = [];
        foreach (Wire::rows($data, 'PDFTemplates') as $row) {
            $pdfTemplates[] = new PdfTemplate(
                uid: Wire::string($row, 'Uid'),
                name: Wire::string($row, 'Name'),
                type: Wire::string($row, 'Type'),
                language: Wire::string($row, 'Language'),
                country: Wire::string($row, 'Country'),
                brandName: Wire::string($row, 'BrandName'),
            );
        }

        return new ListTemplatesResult($templates, $pdfTemplates);
    }
}
