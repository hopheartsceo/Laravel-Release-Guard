<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Tests\Unit\Analysis\Application;

use Hopheartsceo\ReleaseGuard\Analysis\Application\ModelIndexService;
use Hopheartsceo\ReleaseGuard\Domain\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class ModelIndexServiceTest extends TestCase
{
    public function test_model_table_is_resolved_from_laravel_convention(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OrderItem extends Model
{
}
PHP);

        $model = $index->find(
            'App\Models\OrderItem',
        );

        $this->assertNotNull($model);
        $this->assertSame(
            'order_items',
            $model->table,
        );
        $this->assertNull($model->reason);
    }

    public function test_literal_table_property_overrides_convention(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $table = 'legacy_users';
}
PHP);

        $model = $index->find('App\Models\User');

        $this->assertNotNull($model);
        $this->assertSame(
            'legacy_users',
            $model->table,
        );
        $this->assertNull($model->reason);
    }

    public function test_dynamic_table_property_is_not_invented(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $table = TABLE_NAME;
}
PHP);

        $model = $index->find('App\Models\User');

        $this->assertNotNull($model);
        $this->assertNull($model->table);
        $this->assertSame(
            'dynamic_table_property',
            $model->reason,
        );
    }

    public function test_custom_get_table_is_unknown(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    public function getTable()
    {
        return resolveTableName();
    }
}
PHP);

        $model = $index->find('App\Models\User');

        $this->assertNotNull($model);
        $this->assertNull($model->table);
        $this->assertSame(
            'custom_get_table',
            $model->reason,
        );
    }

    public function test_model_subclass_is_discovered_without_instantiation(): void
    {
        $files = [
            new SourceFile(
                path: 'app/Models/BaseModel.php',
                contents: <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
}
PHP,
            ),
            new SourceFile(
                path: 'app/Models/Invoice.php',
                contents: <<<'PHP'
<?php

namespace App\Models;

final class Invoice extends BaseModel
{
}
PHP,
            ),
        ];

        $index = (new ModelIndexService())->build(
            $files,
        );

        $model = $index->find(
            'App\Models\Invoice',
        );

        $this->assertNotNull($model);
        $this->assertSame(
            'invoices',
            $model->table,
        );
    }

    public function test_explicit_parent_table_is_inherited(): void
    {
        $files = [
            new SourceFile(
                path: 'app/Models/LegacyModel.php',
                contents: <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class LegacyModel extends Model
{
    protected $table = 'legacy_records';
}
PHP,
            ),
            new SourceFile(
                path: 'app/Models/LegacyRecord.php',
                contents: <<<'PHP'
<?php

namespace App\Models;

final class LegacyRecord extends LegacyModel
{
}
PHP,
            ),
        ];

        $index = (new ModelIndexService())->build(
            $files,
        );

        $model = $index->find(
            'App\Models\LegacyRecord',
        );

        $this->assertNotNull($model);
        $this->assertSame(
            'legacy_records',
            $model->table,
        );
    }

    public function test_default_laravel_authenticatable_user_is_indexed(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
}
PHP);

        $model = $index->find('App\Models\User');

        $this->assertNotNull($model);
        $this->assertSame('users', $model->table);
        $this->assertNull($model->reason);
    }

    public function test_non_model_class_is_not_indexed(): void
    {
        $index = $this->build(<<<'PHP'
<?php

namespace App\Services;

final class UserService extends Service
{
}
PHP);

        $this->assertSame(
            [],
            $index->models(),
        );
    }

    private function build(string $source)
    {
        return (new ModelIndexService())->build([
            new SourceFile(
                path: 'app/Models/TestModel.php',
                contents: $source,
            ),
        ]);
    }
}
