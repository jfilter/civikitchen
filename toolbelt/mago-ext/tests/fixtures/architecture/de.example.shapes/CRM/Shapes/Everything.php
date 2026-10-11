<?php

declare(strict_types=1);

use Civi\Foreign\Imported;

#[Civi\Foreign\Marker]
class CRM_Shapes_Everything extends CRM_Foreign_Base implements CRM_Foreign_Contract
{
    use CRM_Foreign_Behaviour;

    private CRM_Foreign_Property $property;

    /** @var CRM_Foreign_DocVar */
    private $documented;

    public function __construct(CRM_Foreign_Param $param)
    {
        unset($param);
    }

    public function returns(): ?CRM_Foreign_Returned
    {
        return null;
    }

    /**
     * @param CRM_Foreign_DocParam $value
     * @return array<int, CRM_Foreign_Generic>
     */
    public function documented($value)
    {
        return [];
    }

    /** @return CRM_Foreign_DocReturn */
    public function docReturn()
    {
        return $this->documented;
    }

    public function body(object $value): void
    {
        new CRM_Foreign_Created();
        CRM_Foreign_Statics::make();
        echo CRM_Foreign_Statics::LIMIT;
        echo CRM_Foreign_Statics::$count;
        echo CRM_Foreign_Named::class;
        echo CRM_Core_DAO::DB;
        Imported::make();
        new CRM_Shapes_Everything(new CRM_Foreign_Param());
        if ($value instanceof CRM_Foreign_Checked) {
            return;
        }
        try {
            CRM_Shapes_Own::run();
        } catch (CRM_Foreign_Failure $e) {
            unset($e);
        }
        $closure = static function (CRM_Foreign_ClosureParam $param): void {
            unset($param);
        };
        unset($closure);
        echo 'CRM_Foreign_InString';
        CRM_Foreign_helper();
        echo CRM_FOREIGN_FLAG;
    }

    public function inferred()
    {
        return new CRM_Foreign_Created();
    }

    /** @param CRM_Foreign_Param $param */
    public function both(CRM_Foreign_Param $param): void
    {
        unset($param);
    }

    public function overrides(): void
    {
        parent::overrides();
        new class extends CRM_Shapes_Own {
            public function run(): void
            {
                parent::run();
            }
        };
    }
}
