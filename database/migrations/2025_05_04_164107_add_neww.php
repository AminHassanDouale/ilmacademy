<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécuter les migrations pour corriger la structure de la table activity_logs.
     */
    public function up(): void
    {
        // Vérifier si la table existe déjà
        if (Schema::hasTable('activity_logs')) {
            // Vérifier les colonnes existantes pour éviter les erreurs de duplication
            Schema::table('activity_logs', function (Blueprint $table) {
                // Ajouter les colonnes manquantes avec des valeurs par défaut
                if (!Schema::hasColumn('activity_logs', 'action')) {
                    $table->string('action')->default('access')->after('user_id');
                }

                if (!Schema::hasColumn('activity_logs', 'activity_type')) {
                    $table->string('activity_type')->nullable()->after('action');
                }

                if (!Schema::hasColumn('activity_logs', 'activity_description')) {
                    $table->text('activity_description')->nullable()->after('activity_type');
                }

                if (!Schema::hasColumn('activity_logs', 'description')) {
                    $table->text('description')->nullable()->after('activity_description');
                }

                if (!Schema::hasColumn('activity_logs', 'subject_type')) {
                    $table->string('subject_type')->nullable()->after('description');
                }

                if (!Schema::hasColumn('activity_logs', 'subject_id')) {
                    $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
                }

                // Assurer que tous les index nécessaires existent
                if (!Schema::hasColumn('activity_logs', 'loggable_type') || !Schema::hasColumn('activity_logs', 'loggable_id')) {
                    $table->string('loggable_type')->nullable()->after('subject_id');
                    $table->unsignedBigInteger('loggable_id')->nullable()->after('loggable_type');
                    $table->index(['loggable_type', 'loggable_id']);
                }

                // Ajouter un index pour les nouveaux champs
                $table->index(['subject_type', 'subject_id']);
            });
        } else {
            // Créer la table complète si elle n'existe pas
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action')->default('access');  // Action par défaut
                $table->string('activity_type')->nullable();  // Type d'activité (pour le nouveau schéma)
                $table->text('activity_description')->nullable(); // Description pour le nouveau schéma
                $table->text('description')->nullable();      // Description pour l'ancien schéma
                $table->string('subject_type')->nullable();   // Type du sujet (nouveau schéma)
                $table->unsignedBigInteger('subject_id')->nullable(); // ID du sujet (nouveau schéma)
                $table->string('loggable_type')->nullable();  // Type de l'élément associé (ancien schéma)
                $table->unsignedBigInteger('loggable_id')->nullable(); // ID de l'élément associé (ancien schéma)
                $table->string('ip_address')->nullable();
                $table->json('additional_data')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['loggable_type', 'loggable_id']);
            });
        }
    }

    /**
     * Annuler les migrations.
     */
    public function down(): void
    {
        // Ne pas supprimer la table, juste retirer les modifications
        Schema::table('activity_logs', function (Blueprint $table) {
            // Suppression des index
            $table->dropIndex(['subject_type', 'subject_id']);

            // Suppression des nouvelles colonnes
            $table->dropColumn([
                'activity_type',
                'activity_description',
                'subject_type',
                'subject_id'
            ]);
        });
    }
};
