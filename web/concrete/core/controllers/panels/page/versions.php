<?
defined('C5_EXECUTE') or die("Access Denied.");
class Concrete5_Controller_Panel_Page_Versions extends PanelController {

	protected $viewPath = '/system/panels/page/versions';
	public function canViewPanel() {
		return $this->permissions->canViewPageVersions() || $this->permissions->canEditPageVersions();
	}

	protected function getPageVersionListResponse($currentPage = false) {
		$vl = new VersionList($this->page);
		$vl->setItemsPerPage(20);
		$vArray = $vl->getPage($currentPage);
		
		$r = new PageEditVersionResponse();
		$r->setPage($this->page);
		$r->setVersionList($vl);
		$cpCanDeletePageVersions = $this->permissions->canDeletePageVersions();
		foreach($vArray as $v) {
			$r->addCollectionVersion($v);
		}
		return $r;
	}

	public function view() {
		$this->requireAsset('javascript', 'underscore');
		$r = $this->getPageVersionListResponse();
		$this->set('response', $r);
	}

	public function get_json() {
		$currentPage = false;
		if ($_POST['currentPage']) {
			$currentPage = Loader::helper('security')->sanitizeInt($_POST['currentPage']);
		}
		$r = $this->getPageVersionListResponse($currentPage);
		$r->outputJSON();
	}

	public function duplicate() {
		if ($this->validateSubmitPanel()) {
			$this->page->loadVersionObject($this->request->request->get('cvID'));
			$nc = $this->page->cloneVersion(t('Copy of Version: %s', $this->page->getVersionID()));
			$v = $nc->getVersionObject();
			$r = new PageEditVersionResponse();
			$r->setMessage(t('Version %s copied successfully into. New version %s.', $this->request->request->get('cvID'), $v->getVersionID()));
			$r->addCollectionVersion($v);
			$r->outputJSON();
		}
	}

	public function new_page() {
		if ($this->validateSubmitPanel()) {
			$c = $this->page;
			$r = new PageEditVersionResponse();
			$c->loadVersionObject($_REQUEST['cvID']);
			$nc = $c->cloneVersion(t('New Page Created From Version'));
			$v = $nc->getVersionObject();
			$drafts = Page::getByPath(PAGE_DRAFTS_PAGE_PATH);
			$nc = $c->duplicate($drafts);
			$nc->deactivate();
			$nc->move($drafts);
			// now we delete all but the new version
			$vls = new VersionList($nc);
			$vls->setItemsPerPage(-1);
			$vArray = $vls->getPage();
			for ($i = 1; $i < count($vArray); $i++) {
				$cv = $vArray[$i];
				$cv->delete();
			}
			// now, we delete the version we duped on the current page, since we don't need it anymore.
			$v->delete();
			// finally, we redirect the user to the new drafts page in composer mode.
			$r = new PageEditVersionResponse();
			$r->setPage($nc);
			$r->setRedirectURL(BASE_URL . DIR_REL . '/' . DISPATCHER_FILENAME . '?cID=' . $nc->getCollectionID() . '&ctask=check-out-first&' . Loader::helper('validation/token')->getParameter());
			$r->outputJSON();
		}
	}

	public function delete() {
		if ($this->validateSubmitPanel()) {
			$r = new PageEditVersionResponse();
			$c = $this->page;
			$cp = new Permissions($this->page);
			if ($cp->canDeletePageVersions()) {
				$versions = 0;
				$r = new PageEditVersionResponse();
				$r->setPage($c);
				foreach($_POST['cvID'] as $cvID) {
					$versions++;
					$v = CollectionVersion::get($c, $cvID);
					if (is_object($v)) {
						if (!$v->isApproved()) {
							$r->addCollectionVersion($v);
							$v->delete();							
						}
					}
				}
				$r->setMessage(t2('%s version deleted successfully', '%s versions deleted successfully.', count($r->getCollectionVersions())));
			} else {
				$e = Loader::helper('validation/error');
				$e->add(t('You do not have permission to delete page versions.'));
				$r = new PageEditResponse($e);
			}
			$r->outputJSON();
		}
	}


	public function approve() {
		$c = $this->page;
		$cp = $this->permissions;
		if ($this->validateSubmitPanel()) {
			$r = new PageEditVersionResponse();
			if ($cp->canApprovePageVersions()) {
				$ov = CollectionVersion::get($c, 'ACTIVE');
				if (is_object($ov)) {
					$ovID = $ov->getVersionID();
				}
				$nvID = $_REQUEST['cvID'];

				$r = new PageEditVersionResponse();
				$r->setPage($c);
				$u = new User();
				$pkr = new ApprovePagePageWorkflowRequest();
				$pkr->setRequestedPage($c);
				$v = CollectionVersion::get($c, $_REQUEST['cvID']);
				$pkr->setRequestedVersionID($v->getVersionID());
				$pkr->setRequesterUserID($u->getUserID());
				$response = $pkr->trigger();
				if ($response instanceof WorkflowProgressResponse) {
					// we are deferred
					$r->setMessage(t('<strong>Request Saved.</strong> You must complete the workflow before this change is active.'));
				} else {
					if ($ovID) {
						$r->addCollectionVersion(CollectionVersion::get($c, $ovID));
					}
					$r->addCollectionVersion(CollectionVersion::get($c, $nvID));
					$r->setMessage(t('Version %s approved successfully', $v->getVersionID()));
				}
			} else {
				$e = Loader::helper('validation/error');
				$e->add(t('You do not have permission to approve page versions.'));
				$r = new PageEditResponse($e);
			}

			$r->outputJSON();
		}
	}
}