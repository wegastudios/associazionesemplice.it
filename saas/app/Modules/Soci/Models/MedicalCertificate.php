<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Core\Contracts\OrgModel;
use App\Modules\Soci\Contracts\CertificateKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Certificato medico: dato sanitario. Tipo, medico e nome del file sono cifrati e marcati «health»: ogni lettura
 * finisce nel registro delle azioni.
 *
 * @property string $id
 * @property string $person_id
 * @property string $kind
 * @property string|null $doctor
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $expires_on
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property int|null $file_size
 * @property string|null $file_sha256
 */
final class MedicalCertificate extends OrgModel
{
    use Audited;
    use SoftDeletes;

    protected $table = 'app.medical_certs';

    /** @var list<string> */
    protected $fillable = ['person_id', 'kind', 'doctor', 'issued_on', 'expires_on', 'file_path', 'file_name', 'file_mime', 'file_size', 'file_sha256', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['kind_enc', 'doctor_enc', 'file_name_enc'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => Encrypted::class.':health',
            'doctor' => Encrypted::class.':health',
            'file_name' => Encrypted::class.':health',
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'file_size' => 'integer',
        ];
    }

    public function kindLabel(): string
    {
        return CertificateKind::tryFrom($this->kind)?->label() ?? $this->kind;
    }
}
