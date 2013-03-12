<?php

class ContentController extends CiiController
{
	/**
	 * Base filter, allows logged in and non-logged in users to cache the page
	 */
	public function filters()
    {
        $id = Yii::app()->getRequest()->getQuery('id');
        $key = false;
        
        if ($id != NULL)
		{
			$lastModified = Yii::app()->db->createCommand("SELECT UNIX_TIMESTAMP(GREATEST((SELECT IFNULL(MAX(updated),0) FROM content WHERE id = {$id} AND vid = (SELECT MAX(vid) FROM content AS content2 WHERE content2.id = content.id)), (SELECT IFNULL(MAX(updated), 0) FROM comments WHERE content_id = {$id})))")->queryScalar();
			$theme = Cii::get(Configuration::model()->findByAttributes(array('key'=>'theme')), 'value');
			
			$keyFile = ContentMetadata::model()->findByAttributes(array('content_id'=>$id, 'key'=>'view'));
			
			if ($keyFile != NULL)
			    $key = dirname(__FILE__) . '/../../themes/' . $theme . '/views/content/' . $keyFile->value . '.php';
			
			if ($key && file_exists($key))
				$lastModified = filemtime($key) >= $lastModified ? filemtime($key) : $lastModified;
			
			$eTag = $this->id . Cii::get($this->action, 'id', NULL) . $id . Cii::get(Yii::app()->user->id, 0) . $lastModified;
			
            return array(
                'accessControl',
                array(
                    'CHttpCacheFilter + index',
                    'cacheControl'=>Cii::get(Yii::app()->user->id) == NULL ? 'public' : 'private' .', no-cache, must-revalidate',
                    'etagSeed'=> YII_DEBUG ? mt_rand() : $eTag
                ),
            );
		}
		return parent::filters();
    }
	
	
	/**
	 * Specifies the access control rules.
	 * This method is used by the 'accessControl' filter.
	 * @return array access control rules
	 */
	public function accessRules()
	{
		return array(
			array('allow',  // Allow all users to any section
				'actions' => array('index', 'password', 'list', 'rss'),
				'users'=>array('*'),
			),
			array('allow',  // deny all users
				'actions' => array('like'),
				'users'=>array('@'),
			),
			array('deny',  // deny all users
				'users'=>array('*'),
			),
		);
	}
	
	/**
	 * Verifies that our request does not produce duplicate content (/about == /content/index/2), and prevents direct access to the controller
	 * protecting it from possible attacks.
	 * @param $id	- The content ID we want to verify before proceeding
	 **/
	private function beforeCiiAction($id)
	{
		// If we do not have an ID, consider it to be null, and throw a 404 error
		if ($id == NULL)
			throw new CHttpException(404,'The specified post cannot be found.');
		
		// Retrieve the HTTP Request
		$r = new CHttpRequest();
		
		// Retrieve what the actual URI
		$requestUri = str_replace($r->baseUrl, '', $r->requestUri);
		
		// Retrieve the route
		$route = '/' . $this->getRoute() . '/' . $id;
		$requestUri = preg_replace('/\?(.*)/','',$requestUri);
		
		// If the route and the uri are the same, then a direct access attempt was made, and we need to block access to the controller
		if ($requestUri == $route)
			throw new CHttpException(404, 'The requested post cannot be found.');
	}
	
	/**
	 * Handles all incoming requests for the entire site that are not previous defined in CUrlManager
	 * Requests come in, are verified, and then pulled from the database dynamically
	 * @param $id	- The content ID that we want to pull from the database
	 * @return $this->render() - Render of page that we want to display
	 **/
	public function actionIndex($id=NULL)
	{
		// Run a pre check of our data
		$this->beforeCiiAction($id);
		
		// Retrieve the data
		$content = Content::model()->with('category')->findByPk($id);
        
		if ($content->status != 1)
			throw new CHttpException('404', 'The article you specified does not exist. If you bookmarked this page, please delete it.');
        
		$this->breadcrumbs = array_merge(Categories::model()->getParentCategories($content['category_id']), array($content['title']));
		
		// Check for a password
		if ($content->attributes['password'] != '')
		{
			// Check SESSION to see if a password is set
			$tmpPassword = $_SESSION['password'][$id];
			
			if ($tmpPassword != $content->attributes['password'])
			{
				$this->redirect(Yii::app()->createUrl('/content/password/' . $id));
			}
		}
		
		// Parse Metadata
		$meta = Content::model()->parseMeta($content->metadata);
        
		// Set the layout
		$this->setLayout($content->layout);
        
		$this->setPageTitle(Yii::app()->name . ' | ' . $content->title);
	
		$this->render($content->view, array(
				'id'=>$id, 
				'data'=>$content, 
				'meta'=>$meta,
				'comments'=>Comments::model()->countByAttributes(array('content_id' => $content->id)),
				'model'=>Comments::model()
			)
		);
	}
	
	/**
	 * Provides functionality for "liking and un-liking" a post
	 * @param int $id		The Content ID
	 */
	public function actionLike($id=NULL)
	{
		$this->layout=false;
		header('Content-type: application/json');
		
		// Load the content
		$content = Content::model()->findByPk($id);
		if ($id === NULL || $content === NULL)
		{
			echo CJavaScript::jsonEncode(array('status' => 'error', 'message' => 'Unable to access post'));
			return Yii::app()->end();
		}
		
		// Load the user likes, create one if it does not exist
		$user = UserMetadata::model()->findByAttributes(array('user_id' => Yii::app()->user->id, 'key' => 'likes'));
		if ($user === NULL)
		{
			$user = new UserMetadata;
			$user->user_id = Yii::app()->user->id;
			$user->key = 'likes';
			$user->value = json_encode(array());
		}
		
		$likes = json_decode($user->value, true);
		if (in_array($id, array_values($likes)))
		{
			$content->like_count -= 1;
			if ($content->like_count <= 0)
				$content->like_count = 0;
			$element = array_search($id, $likes);
			unset($likes[$element]);
		}
		else
		{
			$content->like_count += 1;
			array_push($likes, $id);
		}
		
		$user->value = json_encode($likes);
		Cii::debug($likes);
		if (!$user->save())
		{
			Cii::Debug($user->getErrors());
			Cii::Debug(Yii::app()->user->id);
			echo CJavaScript::jsonEncode(array('status' => 'error', 'message' => 'Unable to save user like'));
			return Yii::app()->end();
		}

		if (!$content->save())
		{
			echo CJavaScript::jsonEncode(array('status' => 'error', 'message' => 'Unable to save like'));
			return Yii::app()->end();
		}
		
		echo CJavaScript::jsonEncode(array('status' => 'success', 'message' => 'Liked saved'));
		return Yii::app()->end();
	}
	
	/**
	 * Forces a password to be assigned before the user can proceed to the previous page
	 * @param $id - ID of the content we want to investigate
	 **/
	public function actionPassword($id=NULL)
	{	
		$this->setPageTitle(Yii::app()->name . ' | Password Requires');
		
		if ($id == NULL)
			$this->redirect(Yii::app()->user->returnUrl);
		
		if (!isset($_SESSION['password']))
			$_SESSION['password'] = array('tries'=>0);
			
		if (isset($_POST['password']))
		{
			$content = Content::model()->findByPk($id);
			if ($_POST['password'] == $content->attributes['password'])
			{
				$_SESSION['password'][$_POST['id']] = $_POST['password'];
				$_SESSION['password']['tries'] = 0;
				$this->redirect(Yii::app()->createUrl($content->attributes['slug']));
			}
			else
				$_SESSION['password']['tries'] = $_SESSION['password']['tries'] + 1;
            
		}
		$themeView = Configuration::model()->findByAttributes(array('key'=>'themePasswordView'))->value;
		if ($themeView === NULL || $themeView != 1)
			Yii::app()->setTheme('default');
		
		$this->layout = 'main';
		$this->render('password', array('id'=>$id));
	}
	
	/*
	 * Displays a listing of all blog posts for all time in all categories
	 * Is used as a generic catch all behavior
	 */
	public function actionList()
	{
		$this->setPageTitle('All Content');
		$this->setLayout('default');
		
		$this->breadcrumbs = array('Blogroll');
		
		$data = array();
		$pages = array();
		$itemCount = 0;
		$pageSize = Cii::get(Configuration::model()->findByAttributes(array('key'=>'contentPaginationSize')), 'value', 10);		
		
		$criteria=new CDbCriteria;
        $criteria->order = 'created DESC';
        $criteria->limit = $pageSize;
		$criteria->addCondition("vid=(SELECT MAX(vid) FROM content WHERE id=t.id)")
		         ->addCondition('type_id >= 2')
		         ->addCondition('password = ""')
		         ->addCondition('status = 1');
		
		$itemCount = Content::model()->count($criteria);
		$pages=new CPagination($itemCount);
		$pages->pageSize=$pageSize;
		
		$criteria->offset = $criteria->limit*($pages->getCurrentPage());
		$data = Content::model()->findAll($criteria);
		$pages->applyLimit($criteria);
		
		$this->render('all', array('data'=>$data, 'itemCount'=>$itemCount, 'pages'=>$pages));
	}
	
	/**
	 * Displays either all posts or all posts for a particular category_id if an $id is set in RSS Format
	 * So that RSS Readers can access the website
	 */
	public function actionRss($id=NULL)
	{
		$this->layout=false;
		$criteria=new CDbCriteria;
		$criteria->addCondition("vid=(SELECT MAX(vid) FROM content WHERE id=t.id)")
		         ->addCondition('type_id >= 2')
		         ->addCondition('status = 1');
                 
		if ($id != NULL)
			$criteria->addCondition("category_id = " . $id);
					
		$criteria->order = 'created DESC';
		$data = Content::model()->findAll($criteria);
		
		$this->renderPartial('application.views.site/rss', array('data'=>$data));
		return;
	}
}
?>
