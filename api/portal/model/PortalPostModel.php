<?php
// +----------------------------------------------------------------------
// | ThinkCMF [ WE CAN DO IT MORE SIMPLE ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2017 http://www.thinkcmf.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: pl125 <xskjs888@163.com>
// +----------------------------------------------------------------------
namespace api\portal\model;

use think\Db;
use think\db\Query;
use think\Model;

/**
 * @method getFieldById($id, $string)
 * @property mixed id
 */
class PortalPostModel extends Model
{
    /**
     * 模型名称
     * @var string
     */
    protected $name = 'portal_post';

    //设置只读字段
    protected $readonly = ['user_id'];
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = true;

    //类型转换
    protected $type = [
        'more' => 'array',
    ];

    /**
     *  关联 user表
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('api\portal\model\UserModel', 'user_id');
    }

    /**
     * 关联 user表
     * @return \think\model\relation\BelongsTo
     */
    public function articleUser()
    {
        return $this->belongsTo('api\portal\model\UserModel', 'user_id')->field('id,user_nickname');
    }

    /**
     * 关联分类表
     * @return \think\model\relation\BelongsToMany
     */
    public function categories()
    {
        return $this->belongsToMany('api\portal\model\PortalCategoryModel', 'portal_category_post', 'category_id', 'post_id');
    }

    /**
     * 关联标签表
     * @return \think\model\relation\BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany('api\portal\model\PortalTagModel', 'portal_tag_post', 'tag_id', 'post_id');
    }

    /**
     * 关联 回收站 表
     * @return \think\model\relation\HasOne
     */
    public function recycleBin()
    {
        return $this->hasOne('api\portal\model\RecycleBinModel', 'object_id');
    }

    /**
     * published_time   自动转化
     * @param $value
     * @return string
     */
    public function getPublishedTimeAttr($value)
    {
        // 兼容老版本 1.0.0的客户端
        $apiVersion = request()->header('XX-Api-Version');
        if (empty($apiVersion)) {
            return date('Y-m-d H:i:s', $value);
        } else {
            return $value;
        }
    }

    /**
     * published_time   自动转化
     * @param $value
     * @return int
     */
    public function setPublishedTimeAttr($value)
    {
        if (is_numeric($value)) {
            return $value;
        }
        return strtotime($value);
    }

    public function getPostTitleAttr($value)
    {
        return htmlspecialchars_decode($value);
    }

    public function getPostExcerptAttr($value)
    {
        return htmlspecialchars_decode($value);
    }

    /**
     * post_content 自动转化
     * @param $value
     * @return string
     */
    public function getPostContentAttr($value)
    {
        return cmf_replace_content_file_url(htmlspecialchars_decode($value));
    }

    /**
     * post_content 自动转化
     * @param $value
     * @return string
     */
    public function setPostContentAttr($value)
    {
        return htmlspecialchars(cmf_replace_content_file_url(htmlspecialchars_decode($value), true));
    }

    /**
     * Thumbnail 自动转化
     * @param $value
     * @return array
     */
    public function getThumbnailAttr($value)
    {
        return cmf_get_image_url($value);
    }

    /**
     * more 自动转化
     * @param $value
     * @return array
     */
    public function getMoreAttr($value)
    {
        $more = json_decode($value, true);
        if (!empty($more['thumbnail'])) {
            $more['thumbnail'] = cmf_get_image_url($more['thumbnail']);
        }

        if (!empty($more['audio'])) {
            $more['audio'] = cmf_get_file_download_url($more['audio']);
        }

        if (!empty($more['video'])) {
            $more['video'] = cmf_get_file_download_url($more['video']);
        }

        if (!empty($more['photos'])) {
            foreach ($more['photos'] as $key => $value) {
                $more['photos'][$key]['url'] = cmf_get_image_url($value['url']);
            }
        }

        if (!empty($more['files'])) {
            foreach ($more['files'] as $key => $value) {
                $more['files'][$key]['url'] = cmf_get_file_download_url($value['url']);
            }
        }
        return $more;
    }

    /**
     * 文章查询
     * @param array $filter 数据
     * @return array|\PDOStatement|string|Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function articleFind($filter)
    {
        $result = $this
            ->where(function (Query $query) use ($filter) {
                if (!empty($filter['id'])) {
                    $query->where('id', $filter['id']);
                }
                if (!empty($filter['user_id'])) {
                    $query->where('user_id', $filter['user_id']);
                }
            })
            ->where('delete_time', 0)
            ->where('post_status', 1)
            ->where('post_type', 1)
            ->find();
        return $result;
    }

    /**
     * 会员添加文章
     * @param array $data 文章数据
     * @return $this
     * @throws \think\Exception
     */
    public function addArticle($data)
    {
        if (!empty($data['more'])) {
            $data['more'] = $this->setMoreUrl($data['more']);
        }
        if (!empty($data['thumbnail'])) {
            $data['more']['thumbnail'] = cmf_asset_relative_url($data['thumbnail']);
        }
        $this->allowField(true)->data($data, true)->isUpdate(false)->save();
        $categories = str_to_arr($data['categories']);
        //TODO 无法录入多个分类
        $this->categories()->attach($categories);
        if (!empty($data['post_keywords']) && is_string($data['post_keywords'])) {
            //加入标签
            $data['post_keywords'] = str_replace('，', ',', $data['post_keywords']);
            $keywords              = explode(',', $data['post_keywords']);
            $this->addTags($keywords, $this->id);
        }
        return $this;
    }

    /**
     * 会员文章编辑
     * @param array  $data   文章数据
     * @param int    $id     文章id
     * @param string $userId 文章所属用户id [可选]
     * @return PortalPostModel|bool
     * @throws \think\Exception
     */
    public function editArticle($data, $id, $userId = '')
    {

        if (!empty($userId)) {
            //判断是否属于当前用户的文章
            $isBelong = $this->isuserPost($id, $userId);
            if ($isBelong === false) {
                return $isBelong;
            }
        }

        if (!empty($data['more'])) {
            $data['more'] = $this->setMoreUrl($data['more']);
        }
        if (!empty($data['thumbnail'])) {
            $data['more']['thumbnail'] = cmf_asset_relative_url($data['thumbnail']);
        }
        $data['id'] = $id;
//        $data['post_status'] = empty($data['post_status']) ? 0 : 1;
//        $data['is_top']      = empty($data['is_top']) ? 0 : 1;
//        $data['recommended'] = empty($data['recommended']) ? 0 : 1;
        $this->allowField(true)->data($data, true)->isUpdate(true)->save();

        $categories     = str_to_arr($data['categories']);
        $oldCategoryIds = $this->categories()->column('category_id');

        $sameCategoryIds       = array_intersect($categories, $oldCategoryIds);
        $needDeleteCategoryIds = array_diff($oldCategoryIds, $sameCategoryIds);
        $newCategoryIds        = array_diff($categories, $sameCategoryIds);
        if (!empty($needDeleteCategoryIds)) {
            $this->categories()->detach($needDeleteCategoryIds);
        }

        if (!empty($newCategoryIds)) {
            $this->categories()->attach(array_values($newCategoryIds));
        }

        $keywords = [];

        if (!empty($data['post_keywords'])) {
            if (is_string($data['post_keywords'])) {
                //加入标签
                $data['post_keywords'] = str_replace('，', ',', $data['post_keywords']);
                $keywords              = explode(',', $data['post_keywords']);
            }
        }

        $this->addTags($keywords, $data['id']);

        return $this;
    }

    /**
     * 根据文章关键字，增加标签
     * @param array $keywords  文章关键字数组
     * @param int   $articleId 文章id
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function addTags($keywords, $articleId)
    {
        foreach ($keywords as $key => $value) {
            $keywords[$key] = trim($value);
        }
        $continue = true;
        $names    = $this->tags()->column('name');
        if (!empty($keywords) || !empty($names)) {
            if (!empty($names)) {
                $sameNames         = array_intersect($keywords, $names);
                $keywords          = array_diff($keywords, $sameNames);
                $shouldDeleteNames = array_diff($names, $sameNames);
                if (!empty($shouldDeleteNames)) {
                    $tagIdNames = $this->tags()
                        ->where('name', 'in', $shouldDeleteNames)
                        ->column('pivot.id', 'tag_id');
                    $tagIds     = array_keys($tagIdNames);
                    $tagPostIds = array_values($tagIdNames);
                    $tagPosts   = DB::name('portal_tag_post')
                        ->where('tag_id', 'in', $tagIds)
                        ->field('id,tag_id,post_id')
                        ->select();
                    $keepTagIds = [];
                    foreach ($tagPosts as $key => $tagPost) {
                        if ($articleId != $tagPost['post_id']) {
                            array_push($keepTagIds, $tagPost['tag_id']);
                        }
                    }
                    $keepTagIds         = array_unique($keepTagIds);
                    $shouldDeleteTagIds = array_diff($tagIds, $keepTagIds);
                    Db::name('PortalTag')->delete($shouldDeleteTagIds);
                    Db::name('PortalTagPost')->delete($tagPostIds);
                }
            } else {
                $tagIdNames = DB::name('portal_tag')->where('name', 'in', $keywords)->column('name', 'id');
                if (!empty($tagIdNames)) {
                    $tagIds = array_keys($tagIdNames);
                    $this->tags()->attach($tagIds);
                    $keywords = array_diff($keywords, array_values($tagIdNames));
                    if (empty($keywords)) {
                        $continue = false;
                    }
                }
            }
            if ($continue) {
                foreach ($keywords as $key => $value) {
                    if (!empty($value)) {
                        $this->tags()->attach(['name' => $value]);
                    }
                }
            }
        }
    }

    /**
     * 获取图片附件url相对地址
     * 默认上传名字 *_names  地址 *_urls
     * @param array $annex 上传附件
     * @return array
     */
    public function setMoreUrl($annex)
    {
        $more = [];
        if (!empty($annex)) {
            foreach ($annex as $key => $value) {
                $nameArr = $key . '_names';
                $urlArr  = $key . '_urls';
                if (is_string($value[$nameArr]) && is_string($value[$urlArr])) {
                    $more[$key] = [$value[$nameArr], $value[$urlArr]];
                } elseif (!empty($value[$nameArr]) && !empty($value[$urlArr])) {
                    $more[$key] = [];
                    foreach ($value[$urlArr] as $k => $url) {
                        $url = cmf_asset_relative_url($url);
                        array_push($more[$key], ['url' => $url, 'name' => $value[$nameArr][$k]]);
                    }
                }
            }
        }
        return $more;
    }

    /**
     * 删除文章
     * @param  int|array $ids    文章id
     * @param  string    $userId 文章所属用户id  [可选]
     * @return bool|int 删除结果  true 成功 false 失败  -1 文章不存在
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function deleteArticle($ids, $userId = '')
    {
        $time   = time();
        $result = false;
        $where  = [];

        if (!empty($userId)) {
            if (is_numeric($ids)) {
                $article = $this->find($ids);
                if (!empty($article)) {
                    if ($this->isUserPost($ids, $userId) || $userId == 1) {
                        $where['id'] = $ids;
                    }
                }
            } else {
                $ids      = str_to_arr($ids);
                $articles = $this->where('id', 'in', $ids)->select();
                if (!empty($articles)) {
                    $deleteIds = $this->isUserPosts($ids, $userId);
                    if (!empty($deleteIds)) {
                        $where['id'] = ['in', $deleteIds];
                    }
                }
            }
        } else {
            if (is_numeric($ids)) {
                $article = $this->find($ids);
                if (!empty($article)) {
                    $where['id'] = $ids;
                }
            } else {
                $ids      = str_to_arr($ids);
                $articles = $this->where('id', 'in', $ids)->select();
                if (!empty($articles)) {
                    $where['id'] = ['in', $ids];
                }
            }
        }
        if (empty($article) && empty($articles)) {
            return -1;
        }
        if (!empty($where)) {
            $result = $this->useGlobalScope(false)
                ->where($where)
                ->setField('delete_time', $time);
        }
        if ($result) {
            $data = [
                'create_time' => $time,
                'table_name'  => 'portal_post'
            ];
            if (!empty($article)) {
                $data['name'] = $article['post_title'];
                $article->recycleBin()->save($data);
            }

            if (!empty($articles)) {
                foreach ($articles as $article) {
                    $data['name'] = $article['post_title'];
                    $article->recycleBin()->save($data);
                }
            }
        }
        return $result;
    }

    /**
     * 判断文章所属用户是否为当前用户，超级管理员除外
     * @param   int $id     文章id
     * @param   int $userId 当前用户id
     * @return  boolean     是 true , 否 false
     */
    public function isUserPost($id, $userId)
    {
        $postUserId = $this->getFieldById($id, 'user_id');
        if ($postUserId == $userId || $userId == 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 过滤属于当前用户的文章，超级管理员除外
     * @param   array $ids    文章id的数组
     * @param   int   $userId 当前用户id
     * @return  array     属于当前用户的文章id
     */
    public function isUserPosts($ids, $userId)
    {
        $postIds = $this
            ->useGlobalScope(false)
            ->where('user_id', $userId)
            ->where('id', 'in', $ids)
            ->column('id');
        return array_intersect($ids, $postIds);
    }


}
