<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use BitrixMcp\Indexer\Extract\Component\ExtractedComponent;
use BitrixMcp\Indexer\Extract\Event\ExtractedEvent;
use BitrixMcp\Indexer\Extract\ExtractedAlias;
use BitrixMcp\Indexer\Extract\ExtractedFunction;
use BitrixMcp\Indexer\Extract\ExtractedUsage;
use BitrixMcp\Indexer\Extract\Orm\ExtractedOrmEntity;
use BitrixMcp\Indexer\Extract\ExtractedSymbol;
use BitrixMcp\Indexer\Extract\FileExtraction;
use PDO;
use PDOStatement;

/**
 * Запись извлечённых сущностей.
 *
 * Все методы требуют `moduleId` и `fileId` обязательными аргументами.
 * Записать символ, не указав владельца, нельзя даже по недосмотру — это
 * Принцип II, вынесенный в сигнатуры (а не в проверки во время выполнения).
 */
final class SymbolWriter
{
    private ?PDOStatement $insertSymbol = null;
    private ?PDOStatement $insertInterface = null;
    private ?PDOStatement $insertMethod = null;
    private ?PDOStatement $insertParameter = null;
    private ?PDOStatement $insertAlias = null;
    private ?PDOStatement $insertOrmEntity = null;
    private ?PDOStatement $insertOrmField = null;
    private ?PDOStatement $insertEvent = null;
    private ?PDOStatement $insertRest = null;
    private ?PDOStatement $insertDependency = null;
    private ?PDOStatement $insertComponent = null;
    private ?PDOStatement $insertComponentParam = null;
    private ?PDOStatement $insertFunction = null;
    private ?PDOStatement $insertConstant = null;
    private ?PDOStatement $insertUsage = null;

    private int $symbolCount = 0;
    private int $methodCount = 0;
    private int $aliasCount = 0;
    private int $ormCount = 0;
    private int $ormFieldCount = 0;
    private int $eventCount = 0;
    private int $restCount = 0;
    private int $componentCount = 0;
    private int $functionCount = 0;
    private int $constantCount = 0;
    private int $usageCount = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function write(FileExtraction $extraction, int $moduleId, int $fileId): void
    {
        foreach ($extraction->symbols as $symbol) {
            $this->writeSymbol($symbol, $moduleId, $fileId);
        }

        foreach ($extraction->aliases as $alias) {
            $this->writeAlias($alias, $moduleId, $fileId);
        }

        foreach ($extraction->events as $event) {
            $this->writeEvent($event, $moduleId, $fileId);
        }

        foreach ($extraction->functions as $function) {
            $this->writeFunction($function, $moduleId, $fileId);
        }

        foreach ($extraction->usages as $usage) {
            $this->writeUsage($usage, $moduleId, $fileId);
        }

        foreach ($extraction->restMethods as $method) {
            $this->writeRestMethod($method, $moduleId, $fileId);
        }

        foreach ($extraction->dependencies as $code) {
            $this->writeDependency($moduleId, $code);
        }

        if ($extraction->component !== null) {
            $this->writeComponent($extraction->component, $moduleId, $fileId);
        }
    }

    /** @param array{name: string, handler: string|null, line: int} $method */
    private function writeRestMethod(array $method, int $moduleId, int $fileId): void
    {
        $this->insertRest ??= $this->pdo->prepare(
            'INSERT INTO rest_method (module_id, file_id, name, name_lower, handler, line)
             VALUES (:module_id, :file_id, :name, :name_lower, :handler, :line)'
        );

        $this->insertRest->execute([
            ':module_id' => $moduleId,
            ':file_id' => $fileId,
            ':name' => $method['name'],
            ':name_lower' => mb_strtolower($method['name']),
            ':handler' => $method['handler'],
            ':line' => $method['line'],
        ]);

        $this->restCount++;
    }

    /**
     * Зависимость пишется через UPSERT: один модуль подключает другой
     * из множества файлов, и нас интересует факт связи плюс её частота,
     * а не каждая точка вызова.
     */
    private function writeDependency(int $moduleId, string $code): void
    {
        $this->insertDependency ??= $this->pdo->prepare(
            'INSERT INTO module_dependency (module_id, depends_on_code, occurrences)
             VALUES (:module_id, :code, 1)
             ON CONFLICT (module_id, depends_on_code)
             DO UPDATE SET occurrences = occurrences + 1'
        );

        $this->insertDependency->execute([':module_id' => $moduleId, ':code' => $code]);
    }

    private function writeComponent(ExtractedComponent $component, int $moduleId, int $fileId): void
    {
        $this->insertComponent ??= $this->pdo->prepare(
            'INSERT INTO component
                (module_id, file_id, namespace, name, full_name, title, description, params_resolved)
             VALUES (:module_id, :file_id, :ns, :name, :full, :title, :description, :resolved)'
        );

        $this->insertComponent->execute([
            ':module_id' => $moduleId,
            ':file_id' => $fileId,
            ':ns' => $component->namespace,
            ':name' => $component->name,
            ':full' => $component->fullName,
            ':title' => $component->title,
            ':description' => $component->description,
            ':resolved' => $component->paramsResolved ? 1 : 0,
        ]);

        $componentId = (int)$this->pdo->lastInsertId();
        $this->componentCount++;

        $this->insertComponentParam ??= $this->pdo->prepare(
            'INSERT INTO component_param
                (component_id, position, code, type, default_repr, is_default_resolved,
                 parent_group, multiple, values_repr)
             VALUES (:cid, :pos, :code, :type, :default, :resolved, :parent, :multiple, :values)'
        );

        $position = 0;

        foreach ($component->params as $param) {
            $this->insertComponentParam->execute([
                ':cid' => $componentId,
                ':pos' => $position++,
                ':code' => $param->code,
                ':type' => $param->type,
                ':default' => $param->defaultRepr,
                ':resolved' => $param->isDefaultResolved ? 1 : 0,
                ':parent' => $param->parentGroup,
                ':multiple' => $param->multiple ? 1 : 0,
                ':values' => $param->valuesRepr,
            ]);
        }
    }

    private function writeEvent(ExtractedEvent $event, int $moduleId, int $fileId): void
    {
        $this->insertEvent ??= $this->pdo->prepare(
            'INSERT INTO event
                (module_id, file_id, owner_module, name, name_lower, kind, call_style, params_repr, line)
             VALUES
                (:module_id, :file_id, :owner, :name, :name_lower, :kind, :style, :params, :line)'
        );

        $this->insertEvent->execute([
            ':module_id' => $moduleId,
            ':file_id' => $fileId,
            ':owner' => $event->moduleCode,
            ':name' => $event->name,
            ':name_lower' => mb_strtolower($event->name),
            ':kind' => $event->kind,
            ':style' => $event->callStyle,
            ':params' => $event->paramsRepr,
            ':line' => $event->line,
        ]);

        $this->eventCount++;
    }

    private function writeSymbol(ExtractedSymbol $symbol, int $moduleId, int $fileId): void
    {
        $this->insertSymbol ??= $this->pdo->prepare(
            'INSERT INTO symbol
                (module_id, file_id, fqn, fqn_lower, short_name, short_name_lower, kind,
                 parent_fqn, is_abstract, is_final, is_deprecated, deprecated_note,
                 api_style, doc_comment, line)
             VALUES
                (:module_id, :file_id, :fqn, :fqn_lower, :short, :short_lower, :kind,
                 :parent, :abstract, :final, :deprecated, :note, :api_style, :doc, :line)'
        );

        $this->insertSymbol->execute([
            ':module_id' => $moduleId,
            ':file_id' => $fileId,
            ':fqn' => $symbol->fqn,
            ':fqn_lower' => mb_strtolower($symbol->fqn),
            ':short' => $symbol->shortName,
            ':short_lower' => mb_strtolower($symbol->shortName),
            ':kind' => $symbol->kind,
            ':parent' => $symbol->parentFqn,
            ':abstract' => $symbol->isAbstract ? 1 : 0,
            ':final' => $symbol->isFinal ? 1 : 0,
            ':deprecated' => $symbol->isDeprecated ? 1 : 0,
            ':note' => $symbol->deprecatedNote,
            ':api_style' => $symbol->apiStyle->value,
            ':doc' => $symbol->docComment,
            ':line' => $symbol->line,
        ]);

        $symbolId = (int)$this->pdo->lastInsertId();
        $this->symbolCount++;

        if ($symbol->orm !== null) {
            $this->writeOrm($symbol->orm, $symbolId);
        }

        $this->insertInterface ??= $this->pdo->prepare(
            'INSERT INTO symbol_interface (symbol_id, interface_fqn) VALUES (:sid, :fqn)'
        );

        foreach ($symbol->interfaces as $interface) {
            $this->insertInterface->execute([':sid' => $symbolId, ':fqn' => $interface]);
        }

        $this->insertConstant ??= $this->pdo->prepare(
            'INSERT INTO class_constant
                (symbol_id, name, name_lower, visibility, is_final, value_repr, doc_comment, line)
             VALUES (:sid, :name, :name_lower, :visibility, :final, :value, :doc, :line)'
        );

        foreach ($symbol->constants as $constant) {
            $this->insertConstant->execute([
                ':sid' => $symbolId,
                ':name' => $constant->name,
                ':name_lower' => mb_strtolower($constant->name),
                ':visibility' => $constant->visibility,
                ':final' => $constant->isFinal ? 1 : 0,
                ':value' => $constant->valueRepr,
                ':doc' => $constant->docComment,
                ':line' => $constant->line,
            ]);
            $this->constantCount++;
        }

        $this->insertMethod ??= $this->pdo->prepare(
            'INSERT INTO method
                (symbol_id, name, name_lower, visibility, is_static, is_abstract, is_final,
                 return_type, signature, doc_comment, is_deprecated, line)
             VALUES
                (:sid, :name, :name_lower, :vis, :static, :abstract, :final,
                 :ret, :sig, :doc, :deprecated, :line)'
        );

        $this->insertParameter ??= $this->pdo->prepare(
            'INSERT INTO parameter (method_id, position, name, type, default_repr, by_ref, variadic)
             VALUES (:mid, :pos, :name, :type, :default, :by_ref, :variadic)'
        );

        foreach ($symbol->methods as $method) {
            $this->insertMethod->execute([
                ':sid' => $symbolId,
                ':name' => $method->name,
                ':name_lower' => mb_strtolower($method->name),
                ':vis' => $method->visibility,
                ':static' => $method->isStatic ? 1 : 0,
                ':abstract' => $method->isAbstract ? 1 : 0,
                ':final' => $method->isFinal ? 1 : 0,
                ':ret' => $method->returnType,
                ':sig' => $method->signature,
                ':doc' => $method->docComment,
                ':deprecated' => $method->isDeprecated ? 1 : 0,
                ':line' => $method->line,
            ]);

            $methodId = (int)$this->pdo->lastInsertId();
            $this->methodCount++;

            foreach ($method->parameters as $param) {
                $this->insertParameter->execute([
                    ':mid' => $methodId,
                    ':pos' => $param->position,
                    ':name' => $param->name,
                    ':type' => $param->type,
                    ':default' => $param->defaultRepr,
                    ':by_ref' => $param->byRef ? 1 : 0,
                    ':variadic' => $param->variadic ? 1 : 0,
                ]);
            }
        }
    }

    private function writeOrm(ExtractedOrmEntity $orm, int $symbolId): void
    {
        $this->insertOrmEntity ??= $this->pdo->prepare(
            'INSERT INTO orm_entity
                (symbol_id, table_name, table_name_source, extraction_style, is_complete, incomplete_reason)
             VALUES (:sid, :table, :table_source, :style, :complete, :reason)'
        );

        $this->insertOrmEntity->execute([
            ':sid' => $symbolId,
            ':table' => $orm->tableName,
            ':table_source' => $orm->tableNameSource,
            ':style' => $orm->extractionStyle,
            ':complete' => $orm->isComplete ? 1 : 0,
            ':reason' => $orm->incompleteReason,
        ]);

        $entityId = (int)$this->pdo->lastInsertId();
        $this->ormCount++;

        $this->insertOrmField ??= $this->pdo->prepare(
            'INSERT INTO orm_field
                (orm_entity_id, position, name, data_type, is_primary, is_required, is_autocomplete,
                 default_repr, is_default_resolved, reference_target, join_condition, values_repr)
             VALUES
                (:eid, :pos, :name, :type, :primary, :required, :autocomplete,
                 :default, :resolved, :ref, :join, :values)'
        );

        $position = 0;

        foreach ($orm->fields as $field) {
            $this->insertOrmField->execute([
                ':eid' => $entityId,
                ':pos' => $position++,
                ':name' => $field->name,
                ':type' => $field->dataType,
                ':primary' => $field->isPrimary ? 1 : 0,
                ':required' => $field->isRequired ? 1 : 0,
                ':autocomplete' => $field->isAutocomplete ? 1 : 0,
                ':default' => $field->defaultRepr,
                ':resolved' => $field->isDefaultResolved ? 1 : 0,
                ':ref' => $field->referenceTargetFqn,
                ':join' => $field->joinCondition,
                ':values' => $field->valuesRepr,
            ]);

            $this->ormFieldCount++;
        }
    }

    private function writeAlias(ExtractedAlias $alias, int $moduleId, int $fileId): void
    {
        $this->insertAlias ??= $this->pdo->prepare(
            'INSERT INTO symbol_alias
                (module_id, file_id, alias_fqn, alias_fqn_lower, alias_short_lower, target_fqn, line)
             VALUES
                (:module_id, :file_id, :alias, :alias_lower, :short_lower, :target, :line)'
        );

        $this->insertAlias->execute([
            ':module_id' => $moduleId,
            ':file_id' => $fileId,
            ':alias' => $alias->aliasFqn,
            ':alias_lower' => mb_strtolower($alias->aliasFqn),
            ':short_lower' => mb_strtolower($alias->aliasShortName()),
            ':target' => $alias->targetFqn,
            ':line' => $alias->line,
        ]);

        $this->aliasCount++;
    }

    private function writeFunction(ExtractedFunction $function, int $moduleId, int $fileId): void
    {
        $this->insertFunction ??= $this->pdo->prepare(
            'INSERT INTO global_function
                (module_id, file_id, fqn, fqn_lower, short_name, short_name_lower,
                 signature, return_type, doc_comment, is_deprecated, line)
             VALUES
                (:module, :file, :fqn, :fqn_lower, :short, :short_lower,
                 :signature, :return_type, :doc, :deprecated, :line)'
        );

        $this->insertFunction->execute([
            ':module' => $moduleId,
            ':file' => $fileId,
            ':fqn' => $function->fqn,
            ':fqn_lower' => mb_strtolower($function->fqn),
            ':short' => $function->shortName,
            ':short_lower' => mb_strtolower($function->shortName),
            ':signature' => $function->signature,
            ':return_type' => $function->returnType,
            ':doc' => $function->docComment,
            ':deprecated' => $function->isDeprecated ? 1 : 0,
            ':line' => $function->line,
        ]);
        $this->functionCount++;
    }

    private function writeUsage(ExtractedUsage $usage, int $moduleId, int $fileId): void
    {
        $this->insertUsage ??= $this->pdo->prepare(
            'INSERT INTO usage_reference
                (module_id, file_id, kind, target_fqn, target_fqn_lower,
                 member_name, member_name_lower, context_fqn, context_member, is_dynamic, line)
             VALUES
                (:module, :file, :kind, :target, :target_lower,
                 :member, :member_lower, :context_fqn, :context_member, :dynamic, :line)'
        );

        $this->insertUsage->execute([
            ':module' => $moduleId,
            ':file' => $fileId,
            ':kind' => $usage->kind,
            ':target' => $usage->targetFqn,
            ':target_lower' => $usage->targetFqn === null ? null : mb_strtolower($usage->targetFqn),
            ':member' => $usage->memberName,
            ':member_lower' => mb_strtolower($usage->memberName),
            ':context_fqn' => $usage->contextFqn,
            ':context_member' => $usage->contextMember,
            ':dynamic' => $usage->isDynamic ? 1 : 0,
            ':line' => $usage->line,
        ]);
        $this->usageCount++;
    }

    /** @return array{symbols: int, methods: int, functions: int, constants: int, usages: int, aliases: int, orm_entities: int, orm_fields: int, events: int, rest_methods: int, components: int} */
    public function counts(): array
    {
        return [
            'symbols' => $this->symbolCount,
            'methods' => $this->methodCount,
            'functions' => $this->functionCount,
            'constants' => $this->constantCount,
            'usages' => $this->usageCount,
            'aliases' => $this->aliasCount,
            'orm_entities' => $this->ormCount,
            'orm_fields' => $this->ormFieldCount,
            'events' => $this->eventCount,
            'rest_methods' => $this->restCount,
            'components' => $this->componentCount,
        ];
    }
}
