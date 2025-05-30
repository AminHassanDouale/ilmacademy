<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'action',               // Ce champ est obligatoire
        'activity_type',        // Nouveau champ qui remplace 'action' dans certains de vos composants
        'activity_description', // Nouveau champ qui remplace 'description' dans certains de vos composants
        'description',
        'subject_type',         // Renommé depuis 'loggable_type'
        'subject_id',           // Renommé depuis 'loggable_id'
        'loggable_type',
        'loggable_id',
        'ip_address',
        'additional_data'
    ];

    /**
     * Les attributs à transformer.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'additional_data' => 'array'
    ];

    /**
     * Enregistrer une activité (ancienne méthode)
     *
     * @param int|null $userId ID de l'utilisateur qui effectue l'action
     * @param string $action Type d'action (create, read, update, delete, etc.)
     * @param string $description Description de l'activité
     * @param string|null $loggableType Classe du modèle associé
     * @param int|null $loggableId ID de l'élément associé
     * @param array $additionalData Données supplémentaires à stocker
     * @return self
     */
    public static function log(
        ?int $userId,
        string $action,
        string $description,
        ?string $loggableType = null,
        ?int $loggableId = null,
        array $additionalData = []
    ): self {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'ip_address' => $additionalData['ip'] ?? request()->ip(),
            'additional_data' => $additionalData
        ]);
    }

    /**
     * Enregistrer une activité (nouvelle méthode pour le nouveau schéma)
     *
     * @param int|null $userId ID de l'utilisateur qui effectue l'action
     * @param string $activityType Type d'activité (create, read, update, delete, etc.)
     * @param string $activityDescription Description de l'activité
     * @param mixed $subject Objet associé à l'activité
     * @param array $additionalData Données supplémentaires à stocker
     * @return self
     */
    public static function logActivity(
        ?int $userId,
        string $activityType,
        string $activityDescription,
        $subject = null,
        array $additionalData = []
    ): self {
        $data = [
            'user_id' => $userId,
            'action' => $activityType, // Assure la compatibilité avec l'ancien schéma
            'activity_type' => $activityType,
            'activity_description' => $activityDescription,
            'description' => $activityDescription, // Assure la compatibilité avec l'ancien schéma
            'ip_address' => request()->ip(),
            'additional_data' => $additionalData
        ];

        // Ajouter les informations sur le sujet si fourni
        if ($subject) {
            $data['subject_type'] = get_class($subject);
            $data['subject_id'] = $subject->id;
            $data['loggable_type'] = get_class($subject); // Compatibilité
            $data['loggable_id'] = $subject->id; // Compatibilité
        }

        return self::create($data);
    }

    /**
     * Obtenir l'utilisateur qui a effectué l'action
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtenir le modèle associé (ancienne méthode)
     *
     * @return MorphTo
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Obtenir le modèle associé (nouvelle méthode)
     *
     * @return MorphTo
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}