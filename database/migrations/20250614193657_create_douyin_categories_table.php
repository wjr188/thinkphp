<?php
    // 文件路径: database/migrations/20250614193657_create_douyin_categories_table.php
    // 请确保这个文件里面只有这些代码，替换掉你现在看到的所有内容！

    use think\migration\Migrator;
    use think\migration\Facade as Phinx;
    use think\migration\db\Column;

    class CreateDouyinCategoriesTable extends Migrator
    {
        /**
         * Change Method.
         * Write your reversible migrations using this method.
         * More information on writing migrations is available here:
         * http://docs.phinx.org/en/latest/migrations.html#the-change-method
         *
         * @return void
         */
        public function change()
        {
            // 定义 douyin_categories 表
            $table = $this->table('douyin_categories', ['id' => 'id', 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'comment' => '抖音分类表']);
            $table->addColumn('name', 'string', ['limit' => 255, 'comment' => '分类名称', 'default' => ''])
                  ->addColumn('parent_id', 'integer', ['limit' => 11, 'comment' => '父分类ID (0为顶级分类)', 'default' => 0])
                  ->addColumn('sort', 'integer', ['limit' => 11, 'comment' => '排序值 (越小越靠前)', 'default' => 0])
                  ->addColumn('status', 'integer', ['limit' => 1, 'comment' => '状态 (1:启用, 0:禁用)', 'default' => 1])
                  ->addColumn('create_time', 'datetime', ['comment' => '创建时间', 'null' => false])
                  ->addColumn('update_time', 'datetime', ['comment' => '更新时间', 'null' => false])
                  ->create(); // 创建表
        }

        // down 方法用于回滚迁移，如果需要，你可以在这里定义删除表的逻辑
        // public function down()
        // {
        //     $this->table('douyin_categories')->drop()->save();
        // }
    }
    