<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Presentation boundary for Fast Deploy requests.
 *
 * The controller supplies a normalized, customer-authorized request detail.
 * This class deliberately does not call APIs, infer action authority, or keep
 * state: browser input remains a selector and the controller is the authority.
 */
final class View
{
    /** @param array<string,mixed> $detail @param list<array<string,mixed>> $history */
    public static function render(array $detail, array $history, string $csrf, string $basePath = ''): string
    {
        $locale = is_string($_SESSION['Language'] ?? null) ? $_SESSION['Language']
            : (is_string($GLOBALS['CONFIG']['Language'] ?? null) ? $GLOBALS['CONFIG']['Language'] : 'english');
        $copy = self::copy($locale);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $basePath = self::basePath($basePath);
        $detail = self::detail($detail);
        $history = array_values(array_filter($history, 'is_array'));
        $json = json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $copyJson = json_encode($copy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/templates/fast-deploy-flow.php';
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    /** @param list<array<string,mixed>> $history */
    public static function history(array $history, string $basePath = ''): string
    {
        $locale = is_string($_SESSION['Language'] ?? null) ? $_SESSION['Language']
            : (is_string($GLOBALS['CONFIG']['Language'] ?? null) ? $GLOBALS['CONFIG']['Language'] : 'english');
        $copy = self::copy($locale);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $basePath = self::basePath($basePath);
        ob_start();
        ?><section class="bcp-flow-history" aria-labelledby="bcp-flow-history-title"><div class="bcp-flow-history-title"><h3 id="bcp-flow-history-title"><?= $escape($copy['history']) ?></h3></div><?php self::historyRows($history, $copy, $basePath, $escape); ?></section><?php
        return (string) ob_get_clean();
    }

    /** @return array<string,string> */
    public static function copy(string $locale): array
    {
        $locale = match (strtolower(str_replace('_', '-', $locale))) {
            'pt', 'pt-br', 'portuguese', 'portuguese-br' => 'pt',
            'es', 'es-es', 'spanish' => 'es',
            default => 'en',
        };
        $all = [
            'en' => [
                'eyebrow' => 'Fast Deploy', 'title' => 'Your migration', 'history' => 'Migration history', 'back_home' => 'Back to client area', 'new_migration' => 'New migration',
                'upload_title' => 'Send your complete cPanel backup', 'upload_help' => 'Your file is transferred directly to secure storage.',
                'drop' => 'Drag the backup here or choose a file', 'file_limit' => 'Maximum file size: 50 GB',
                'start_upload' => 'Start secure upload', 'confirm_upload' => 'Start migration', 'cancel' => 'Cancel migration',
                'review_title' => 'Review the discovered domain', 'review_help' => 'Confirm the domain before the new hosting is prepared.',
                'approve' => 'Approve migration', 'retry' => 'Try again', 'refresh' => 'Refresh',
                'staging' => 'Open preview', 'cutover' => 'Connect your domain', 'waiting' => 'Waiting for an update',
                'uploading' => 'Uploading backup', 'complete' => 'Complete', 'failed' => 'Migration could not continue',
                'cancelled' => 'Migration cancelled', 'live' => 'Migration complete', 'domain' => 'Domain',
                'created' => 'Started', 'destination' => 'Destination', 'payment' => 'Commercial status',
                'analysis' => 'Backup analysis', 'components' => 'Migration components', 'unknown' => 'Not available',
                'invoice' => 'Open invoice', 'expires' => 'Deadline', 'grace' => 'Grace period ends', 'retention' => 'Retention ends',
                'no_history' => 'No other migrations yet.', 'open' => 'Open', 'upload_error' => 'The upload could not be completed. Try again.',
                'network_error' => 'Connection problem. Check your network and try again.', 'upload_timeout' => 'The connection took too long. Check your network and try again.', 'part_retry' => 'Retrying file part {part}…',
                'part' => 'Part {current} of {total}', 'selected_file' => 'Selected file', 'remove_file' => 'Remove file',
                'confirm_cancel' => 'Cancel this migration? The current upload will stop.', 'yes_cancel' => 'Yes, cancel', 'no' => 'Keep migration',
                'reconcile' => 'Try purchase again', 'reconcile_error' => 'We could not update the purchase status. Try again.',
                'step_destination' => 'Destination', 'step_backup' => 'Backup', 'step_analysis' => 'Analysis', 'step_review' => 'Review', 'step_migration' => 'Migration', 'step_completion' => 'Completion',
            ],
            'pt' => [
                'eyebrow' => 'Fast Deploy', 'title' => 'Sua migração', 'history' => 'Histórico de migrações', 'back_home' => 'Voltar à área de cliente', 'new_migration' => 'Nova migração',
                'upload_title' => 'Envie seu backup completo do cPanel', 'upload_help' => 'Seu arquivo é enviado diretamente para o armazenamento seguro.',
                'drop' => 'Arraste o backup aqui ou escolha um arquivo', 'file_limit' => 'Tamanho máximo do arquivo: 50 GB',
                'start_upload' => 'Iniciar envio seguro', 'confirm_upload' => 'Iniciar migração', 'cancel' => 'Cancelar migração',
                'review_title' => 'Revise o domínio identificado', 'review_help' => 'Confirme o domínio antes de preparar a nova hospedagem.',
                'approve' => 'Aprovar migração', 'retry' => 'Tentar novamente', 'refresh' => 'Atualizar',
                'staging' => 'Abrir prévia', 'cutover' => 'Conectar seu domínio', 'waiting' => 'Aguardando atualização',
                'uploading' => 'Enviando backup', 'complete' => 'Concluída', 'failed' => 'A migração não pôde continuar',
                'cancelled' => 'Migração cancelada', 'live' => 'Migração concluída', 'domain' => 'Domínio',
                'created' => 'Iniciada', 'destination' => 'Destino', 'payment' => 'Situação comercial',
                'analysis' => 'Análise do backup', 'components' => 'Componentes da migração', 'unknown' => 'Não disponível',
                'invoice' => 'Abrir fatura', 'expires' => 'Prazo', 'grace' => 'Tolerância termina', 'retention' => 'Retenção termina',
                'no_history' => 'Nenhuma outra migração ainda.', 'open' => 'Abrir', 'upload_error' => 'Não foi possível concluir o envio. Tente novamente.',
                'network_error' => 'Problema de conexão. Verifique sua rede e tente novamente.', 'upload_timeout' => 'A conexão demorou demais. Verifique sua rede e tente novamente.', 'part_retry' => 'Tentando novamente a parte {part}…',
                'part' => 'Parte {current} de {total}', 'selected_file' => 'Arquivo selecionado', 'remove_file' => 'Remover arquivo',
                'confirm_cancel' => 'Cancelar esta migração? O envio atual será interrompido.', 'yes_cancel' => 'Sim, cancelar', 'no' => 'Manter migração',
                'reconcile' => 'Tentar novamente contratação', 'reconcile_error' => 'Não foi possível atualizar a situação da contratação. Tente novamente.',
                'step_destination' => 'Destino', 'step_backup' => 'Backup', 'step_analysis' => 'Análise', 'step_review' => 'Revisão', 'step_migration' => 'Migração', 'step_completion' => 'Conclusão',
            ],
            'es' => [
                'eyebrow' => 'Fast Deploy', 'title' => 'Tu migración', 'history' => 'Historial de migraciones', 'back_home' => 'Volver al área de cliente', 'new_migration' => 'Nueva migración',
                'upload_title' => 'Envía tu copia completa de cPanel', 'upload_help' => 'Tu archivo se transfiere directamente al almacenamiento seguro.',
                'drop' => 'Arrastra la copia aquí o elige un archivo', 'file_limit' => 'Tamaño máximo: 50 GB',
                'start_upload' => 'Iniciar envío seguro', 'confirm_upload' => 'Iniciar migración', 'cancel' => 'Cancelar migración',
                'review_title' => 'Revisa el dominio encontrado', 'review_help' => 'Confirma el dominio antes de preparar el nuevo hosting.',
                'approve' => 'Aprobar migración', 'retry' => 'Intentar otra vez', 'refresh' => 'Actualizar',
                'staging' => 'Abrir vista previa', 'cutover' => 'Conectar tu dominio', 'waiting' => 'Esperando actualización',
                'uploading' => 'Enviando copia', 'complete' => 'Completada', 'failed' => 'La migración no pudo continuar',
                'cancelled' => 'Migración cancelada', 'live' => 'Migración completada', 'domain' => 'Dominio',
                'created' => 'Iniciada', 'destination' => 'Destino', 'payment' => 'Estado comercial',
                'analysis' => 'Análisis de la copia', 'components' => 'Componentes de migración', 'unknown' => 'No disponible',
                'invoice' => 'Abrir factura', 'expires' => 'Plazo', 'grace' => 'Fin de tolerancia', 'retention' => 'Fin de retención',
                'no_history' => 'Aún no hay otras migraciones.', 'open' => 'Abrir', 'upload_error' => 'No se pudo completar el envío. Inténtalo otra vez.',
                'network_error' => 'Problema de conexión. Comprueba tu red e inténtalo otra vez.', 'upload_timeout' => 'La conexión tardó demasiado. Comprueba tu red e inténtalo otra vez.', 'part_retry' => 'Reintentando la parte {part}…',
                'part' => 'Parte {current} de {total}', 'selected_file' => 'Archivo seleccionado', 'remove_file' => 'Quitar archivo',
                'confirm_cancel' => '¿Cancelar esta migración? El envío actual se detendrá.', 'yes_cancel' => 'Sí, cancelar', 'no' => 'Mantener migración',
                'reconcile' => 'Intentar contratación otra vez', 'reconcile_error' => 'No se pudo actualizar el estado de contratación. Inténtalo otra vez.',
                'step_destination' => 'Destino', 'step_backup' => 'Copia', 'step_analysis' => 'Análisis', 'step_review' => 'Revisión', 'step_migration' => 'Migración', 'step_completion' => 'Finalización',
            ],
        ];
        $phases = [
            'en' => [
                'draft' => ['Ready to send backup', 'Choose the complete cPanel backup to continue.'], 'uploading' => ['Uploading backup', 'Your backup is being transferred securely.'], 'queued' => ['Migration queued', 'Your migration is waiting to start.'], 'parsing' => ['Analyzing backup', 'We are checking the backup contents.'], 'discovery_review' => ['Review found domain', 'Confirm the domain found in the backup.'], 'provisioning' => ['Preparing hosting', 'We are preparing the destination environment.'], 'importing' => ['Importing backup', 'We are restoring the available account data.'], 'validating' => ['Checking migration', 'We are validating the imported data.'], 'awaiting_approval' => ['Ready for your review', 'Review the temporary environment before approval.'], 'syncing' => ['Finishing migration', 'We are completing the final activation checks.'], 'cutover_pending' => ['Waiting for domain activation', 'Update your domain as instructed to finish activation.'], 'cutover_stalled' => ['Domain needs attention', 'Your domain update still needs attention.'], 'live' => ['Migration complete', 'The destination is live.'], 'failed' => ['Migration could not continue', 'Review the available next action or contact support.'], 'cancelled' => ['Migration cancelled', 'This migration was cancelled before completion.'],
            ],
            'pt' => [
                'draft' => ['Pronto para enviar o backup', 'Escolha o backup completo do cPanel para continuar.'], 'uploading' => ['Enviando backup', 'Seu backup está sendo enviado com segurança.'], 'queued' => ['Migração na fila', 'Sua migração aguarda o início.'], 'parsing' => ['Analisando backup', 'Estamos verificando o conteúdo do backup.'], 'discovery_review' => ['Revisar domínio identificado', 'Confirme o domínio encontrado no backup.'], 'provisioning' => ['Preparando hospedagem', 'Estamos preparando o ambiente de destino.'], 'importing' => ['Importando backup', 'Estamos restaurando os dados disponíveis da conta.'], 'validating' => ['Validando migração', 'Estamos validando os dados importados.'], 'awaiting_approval' => ['Pronto para sua revisão', 'Revise o ambiente temporário antes de aprovar.'], 'syncing' => ['Finalizando migração', 'Estamos concluindo as verificações de ativação.'], 'cutover_pending' => ['Aguardando ativação do domínio', 'Atualize seu domínio conforme indicado para concluir a ativação.'], 'cutover_stalled' => ['Domínio precisa de atenção', 'A atualização do domínio ainda precisa de atenção.'], 'live' => ['Migração concluída', 'O destino está ativo.'], 'failed' => ['A migração não pôde continuar', 'Revise a próxima ação disponível ou contate o suporte.'], 'cancelled' => ['Migração cancelada', 'Esta migração foi cancelada antes da conclusão.'],
            ],
            'es' => [
                'draft' => ['Lista para enviar la copia', 'Elige la copia completa de cPanel para continuar.'], 'uploading' => ['Enviando copia', 'Tu copia se está transfiriendo de forma segura.'], 'queued' => ['Migración en cola', 'Tu migración espera para empezar.'], 'parsing' => ['Analizando copia', 'Estamos comprobando el contenido de la copia.'], 'discovery_review' => ['Revisar dominio encontrado', 'Confirma el dominio encontrado en la copia.'], 'provisioning' => ['Preparando hosting', 'Estamos preparando el entorno de destino.'], 'importing' => ['Importando copia', 'Estamos restaurando los datos disponibles de la cuenta.'], 'validating' => ['Validando migración', 'Estamos validando los datos importados.'], 'awaiting_approval' => ['Lista para tu revisión', 'Revisa el entorno temporal antes de aprobar.'], 'syncing' => ['Finalizando migración', 'Estamos completando las comprobaciones de activación.'], 'cutover_pending' => ['Esperando activación del dominio', 'Actualiza tu dominio según las instrucciones para finalizar.'], 'cutover_stalled' => ['El dominio necesita atención', 'La actualización del dominio aún necesita atención.'], 'live' => ['Migración completada', 'El destino está activo.'], 'failed' => ['La migración no pudo continuar', 'Revisa la siguiente acción disponible o contacta con soporte.'], 'cancelled' => ['Migración cancelada', 'Esta migración se canceló antes de completarse.'],
            ],
        ];
        foreach ($phases[$locale] as $phase => [$label, $help]) {
            $all[$locale]['phase_' . $phase] = $label;
            $all[$locale]['phase_help_' . $phase] = $help;
        }
        $all[$locale] += [
            'component_files' => $locale === 'pt' ? 'Arquivos' : ($locale === 'es' ? 'Archivos' : 'Files'),
            'component_database' => $locale === 'pt' ? 'Banco de dados' : ($locale === 'es' ? 'Base de datos' : 'Database'),
            'component_email_accounts' => $locale === 'pt' ? 'Contas de e-mail' : ($locale === 'es' ? 'Cuentas de correo' : 'Email accounts'),
            'component_email_history' => $locale === 'pt' ? 'Histórico de e-mails' : ($locale === 'es' ? 'Historial de correo' : 'Email history'),
            'status_pending' => $locale === 'pt' ? 'Aguardando' : ($locale === 'es' ? 'En espera' : 'Waiting'),
            'status_running' => $locale === 'pt' ? 'Em andamento' : ($locale === 'es' ? 'En curso' : 'In progress'),
            'status_completed' => $locale === 'pt' ? 'Concluído' : ($locale === 'es' ? 'Completado' : 'Completed'),
            'status_failed' => $locale === 'pt' ? 'Falhou' : ($locale === 'es' ? 'Falló' : 'Failed'),
            'status_skipped' => $locale === 'pt' ? 'Não incluído' : ($locale === 'es' ? 'No incluido' : 'Not included'),
            'discovery_php' => 'PHP', 'discovery_database' => $locale === 'pt' ? 'Banco de dados' : ($locale === 'es' ? 'Base de datos' : 'Database'),
            'discovery_files' => $locale === 'pt' ? 'Arquivos' : ($locale === 'es' ? 'Archivos' : 'Files'),
            'discovery_emails' => $locale === 'pt' ? 'Contas de e-mail' : ($locale === 'es' ? 'Cuentas de correo' : 'Email accounts'),
            'discovery_size' => $locale === 'pt' ? 'Tamanho dos arquivos' : ($locale === 'es' ? 'Tamaño de archivos' : 'File size'),
            'discovery_email_history' => $locale === 'pt' ? 'Histórico de e-mails' : ($locale === 'es' ? 'Historial de correo' : 'Email history'),
            'discovery_multisite' => $locale === 'pt' ? 'Domínios adicionais' : ($locale === 'es' ? 'Dominios adicionales' : 'Additional domains'),
            'commercial_paid' => $locale === 'pt' ? 'Pagamento confirmado' : ($locale === 'es' ? 'Pago confirmado' : 'Payment confirmed'),
            'commercial_awaiting_payment' => $locale === 'pt' ? 'Aguardando pagamento' : ($locale === 'es' ? 'Esperando pago' : 'Awaiting payment'),
            'commercial_ready' => $locale === 'pt' ? 'Pronto para continuar' : ($locale === 'es' ? 'Listo para continuar' : 'Ready to continue'),
            'commercial_payment_due' => $locale === 'pt' ? 'Pagamento pendente' : ($locale === 'es' ? 'Pago pendiente' : 'Payment due'),
            'commercial_trial_active' => $locale === 'pt' ? 'Período de teste ativo' : ($locale === 'es' ? 'Período de prueba activo' : 'Trial active'),
            'commercial_suspension_pending' => $locale === 'pt' ? 'Processando suspensão' : ($locale === 'es' ? 'Procesando suspensión' : 'Processing suspension'),
            'commercial_suspended' => $locale === 'pt' ? 'Serviço suspenso' : ($locale === 'es' ? 'Servicio suspendido' : 'Service suspended'),
            'commercial_termination_pending' => $locale === 'pt' ? 'Processando encerramento' : ($locale === 'es' ? 'Procesando finalización' : 'Processing termination'),
            'commercial_terminated' => $locale === 'pt' ? 'Serviço encerrado' : ($locale === 'es' ? 'Servicio terminado' : 'Service terminated'),
            'commercial_regularized' => $locale === 'pt' ? 'Situação regularizada' : ($locale === 'es' ? 'Situación regularizada' : 'Account regularized'),
            'policy_payment_before' => $locale === 'pt' ? 'Pagamento antes da migração' : ($locale === 'es' ? 'Pago antes de migrar' : 'Payment before migration'),
            'policy_payment_after' => $locale === 'pt' ? 'Pagamento após a migração' : ($locale === 'es' ? 'Pago después de migrar' : 'Payment after migration'),
            'policy_trial' => $locale === 'pt' ? 'Teste por {days} dias' : ($locale === 'es' ? 'Prueba por {days} días' : '{days}-day trial'),
        ];
        return $all[$locale];
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private static function detail(array $detail): array
    {
        $migration = is_array($detail['migration'] ?? null) ? $detail['migration'] : [];
        $actions = is_array($detail['actions'] ?? null) ? $detail['actions'] : [];
        $commercial = is_array($detail['commercial'] ?? null) ? $detail['commercial'] : [];
        return [
            'requestUuid' => self::string($detail['requestUuid'] ?? null),
            'nativeProductId' => self::integer($detail['nativeProductId'] ?? null),
            'partnerPlanId' => self::integer($detail['partnerPlanId'] ?? null),
            'locationId' => self::integer($detail['locationId'] ?? null),
            'commercial' => $commercial,
            'migration' => $migration,
            'actions' => [
                'upload' => ($actions['upload'] ?? false) === true,
                'confirmUpload' => ($actions['confirmUpload'] ?? false) === true,
                'review' => ($actions['review'] ?? false) === true,
                'approve' => ($actions['approve'] ?? false) === true,
                'cancel' => ($actions['cancel'] ?? false) === true,
                'retry' => ($actions['retry'] ?? false) === true,
                'reconcile' => ($actions['reconcile'] ?? false) === true,
            ],
        ];
    }

    private static function string(mixed $value): string { return is_string($value) ? $value : ''; }
    private static function integer(mixed $value): ?int { return is_int($value) && $value > 0 ? $value : null; }

    private static function basePath(string $path): string
    {
        if ($path === '' || $path === '/') { return ''; }
        return str_starts_with($path, '/') && !str_starts_with($path, '//') ? rtrim($path, '/') : '';
    }

    /** @param list<array<string,mixed>> $history @param array<string,string> $copy @param callable(mixed):string $escape */
    private static function historyRows(array $history, array $copy, string $basePath, callable $escape): void
    {
        $rows = array_values(array_filter($history, 'is_array'));
        if ($rows === []) { echo '<p>' . $escape($copy['no_history']) . '</p>'; return; }
        echo '<ul>';
        foreach ($rows as $item) {
            $id = is_string($item['requestUuid'] ?? null) ? $item['requestUuid'] : '';
            if ($id === '') { continue; }
            $migration = is_array($item['migration'] ?? null) ? $item['migration'] : [];
            $domain = $migration['primaryDomain'] ?? $item['primaryDomain'] ?? $item['requestedPrimaryDomain'] ?? $copy['unknown'];
            $phase = is_string($migration['phase'] ?? null) ? $migration['phase'] : (is_string($item['phase'] ?? null) ? $item['phase'] : '');
            $label = $copy['phase_' . $phase] ?? $copy['unknown'];
            echo '<li><span><strong>' . $escape($domain) . '</strong><small>' . $escape($label) . '</small></span><a href="' . $escape($basePath) . '/index.php?m=bladecp&amp;action=fast-deploy&amp;request=' . rawurlencode($id) . '">' . $escape($copy['open']) . '</a></li>';
        }
        echo '</ul>';
    }
}
