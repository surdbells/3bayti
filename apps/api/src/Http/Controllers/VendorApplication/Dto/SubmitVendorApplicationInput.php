<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\VendorApplication\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for the PUBLIC POST /v3/vendor-applications.
 *
 * Required: first_name, last_name, email, phone, business_name.
 * Optional: license_number, category, message.
 *
 * Email is lowercased + trimmed in the constructor (mirrors the
 * entity); phone is whitespace-stripped to a +country-code shape.
 */
final class SubmitVendorApplicationInput
{
    #[Assert\NotBlank(message: 'first_name is required.')]
    #[Assert\Length(max: 100)]
    public readonly string $first_name;

    #[Assert\NotBlank(message: 'last_name is required.')]
    #[Assert\Length(max: 100)]
    public readonly string $last_name;

    #[Assert\NotBlank(message: 'email is required.')]
    #[Assert\Email(message: 'email must be a valid email.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\NotBlank(message: 'phone is required.')]
    #[Assert\Length(min: 7, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+?[1-9]\d{6,18}$/',
        message: 'phone must be digits, optionally with a leading +.',
    )]
    public readonly string $phone;

    #[Assert\NotBlank(message: 'business_name is required.')]
    #[Assert\Length(max: 255)]
    public readonly string $business_name;

    #[Assert\Length(max: 100)]
    public readonly ?string $license_number;

    #[Assert\Length(max: 120)]
    public readonly ?string $category;

    #[Assert\Length(max: 2000)]
    public readonly ?string $message;

    #[Assert\Length(min: 2, max: 2)]
    public readonly string $country_code;

    public function __construct(
        string $first_name = '',
        string $last_name = '',
        string $email = '',
        string $phone = '',
        string $business_name = '',
        ?string $license_number = null,
        ?string $category = null,
        ?string $message = null,
        string $country_code = 'AE',
    ) {
        $this->first_name = trim($first_name);
        $this->last_name = trim($last_name);
        $this->email = strtolower(trim($email));
        $this->phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';
        $this->business_name = trim($business_name);
        $license_number = $license_number !== null ? trim($license_number) : null;
        $this->license_number = ($license_number === '' || $license_number === null) ? null : $license_number;
        $category = $category !== null ? trim($category) : null;
        $this->category = ($category === '' || $category === null) ? null : $category;
        $message = $message !== null ? trim($message) : null;
        $this->message = ($message === '' || $message === null) ? null : $message;
        $cc = strtoupper(trim($country_code));
        $this->country_code = $cc === '' ? 'AE' : $cc;
    }
}
