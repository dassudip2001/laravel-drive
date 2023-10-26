<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddToFavouritesRequest;
use App\Http\Requests\FilesActionRequest;
use App\Http\Requests\ShareFilesRequest;
use App\Http\Requests\StoreFileRequest;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\TrashFilesRequest;
use App\Http\Resources\FileResource;
use App\Jobs\UploadFileToCloudJob;
use App\Mail\ShareFilesMail;
use App\Models\File;
use App\Models\FileShare;
use App\Models\StarredFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class FileController extends Controller
{
    /**
     * The function retrieves files based on search criteria, folder selection, and favorite status,
     * and returns the results in a paginated format.
     * 
     * @param Request request The `` parameter is an instance of the `Illuminate\Http\Request`
     * class, which represents an HTTP request made to the server. It contains information about the
     * request, such as the request method, headers, query parameters, form data, etc.
     * @param string folder The `folder` parameter is a string that represents the path of a folder. It
     * is an optional parameter, which means it can be null. If a folder path is provided, the function
     * will retrieve the folder from the database based on the path and the authenticated user. If no
     * folder path is provided
     * 
     * @return a response based on the request type. If the request wants JSON, it returns the
     * collection of files as JSON. Otherwise, it renders the 'MyFiles' view using the Inertia
     * framework, passing the files, folder, and ancestors as variables.
     */
    public function myFiles(Request $request, string $folder = null)
    {
        $search = $request->get('search');

        if ($folder) {
            $folder = File::query()
                ->where('created_by', Auth::id())
                ->where('path', $folder)
                ->firstOrFail();
        }
        if (!$folder) {
            $folder = $this->getRoot();
        }

        $favourites = (int)$request->get('favourites');

        $query = File::query()
            ->select('files.*')
            ->with('starred')
            ->where('created_by', Auth::id())
            ->where('_lft', '!=', 1)
            ->orderBy('is_folder', 'desc')
            ->orderBy('files.created_at', 'desc')
            ->orderBy('files.id', 'desc');

        if ($search) {
            $query->where('name', 'like', "%$search%");
        } else {
            $query->where('parent_id', $folder->id);
        }

        if ($favourites === 1) {
            $query->join('starred_files', 'starred_files.file_id', '=', 'files.id')
                ->where('starred_files.user_id', Auth::id());
        }

        $files = $query->paginate(100);

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        $ancestors = FileResource::collection([...$folder->ancestors, $folder]);

        $folder = new FileResource($folder);

        return Inertia::render('MyFiles', compact('files', 'folder', 'ancestors'));
    }

    /**
     * The function retrieves trashed files, filters them based on the search query, and returns the
     * results either as a JSON response or renders a view using Inertia.js.
     * 
     * @param Request request The  parameter is an instance of the Request class, which
     * represents an HTTP request. It contains information about the request, such as the request
     * method, headers, and input data.
     * 
     * @return either a JSON response containing the collection of files or an Inertia render of the
     * 'Trash' view with the 'files' variable passed to it.
     */
    public function trash(Request $request)
    {
        $search = $request->get('search');
        $query = File::onlyTrashed()
            ->where('created_by', Auth::id())
            ->orderBy('is_folder', 'desc')
            ->orderBy('deleted_at', 'desc')
            ->orderBy('files.id', 'desc');

        if ($search) {
            $query->where('name', 'like', "%$search%");
        }

        $files = $query->paginate(100);

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        return Inertia::render('Trash', compact('files'));
    }

    /**
     * The function retrieves files that have been shared with the user, filters them based on a search
     * query, paginates the results, and returns them as a JSON response or renders them in an Inertia
     * view.
     * 
     * @param Request request The `` parameter is an instance of the `Illuminate\Http\Request`
     * class. It represents the current HTTP request made to the server and contains information such
     * as the request method, headers, query parameters, form data, etc. In this case, it is used to
     * retrieve the search term entered by
     * 
     * @return either a JSON response containing the collection of File resources or an Inertia render
     * of the 'SharedWithMe' view with the 'files' variable passed as data.
     */
    public function sharedWithMe(Request $request)
    {
        $search = $request->get('search');
        $query = File::getSharedWithMe();

        if ($search) {
            $query->where('name', 'like', "%$search%");
        }

        $files = $query->paginate(100);

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        return Inertia::render('SharedWithMe', compact('files'));
    }

    /**
     * The function retrieves files shared by the user, filters them based on a search query, paginates
     * the results, and returns them as a JSON response or renders a view using the Inertia.js
     * framework.
     * 
     * @param Request request The `` parameter is an instance of the `Illuminate\Http\Request`
     * class. It represents the current HTTP request made to the server and contains information such
     * as the request method, headers, query parameters, form data, etc. In this case, it is used to
     * retrieve the search query parameter.
     * 
     * @return either a JSON response containing the collection of files or an Inertia render of the
     * 'SharedByMe' view with the 'files' variable passed to it.
     */
    public function sharedByMe(Request $request)
    {
        $search = $request->get('search');
        $query = File::getSharedByMe();

        if ($search) {
            $query->where('name', 'like', "%$search%");
        }

        $files = $query->paginate(100);
        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        return Inertia::render('SharedByMe', compact('files'));
    }

    /**
     * The createFolder function creates a new folder in a file system, with an optional parent folder.
     * 
     * @param StoreFolderRequest request The  parameter is an instance of the
     * StoreFolderRequest class, which is used to validate and retrieve the data sent in the request.
     * It contains the information needed to create a new folder.
     */
    public function createFolder(StoreFolderRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        if (!$parent) {
            $parent = $this->getRoot();
        }

        $file = new File();
        $file->is_folder = 1;
        $file->name = $data['name'];

        $parent->appendNode($file);
    }

    /**
     * The `store` function in PHP receives a request to store a file, validates the request data,
     * determines the parent directory for the file, and then either saves the file or saves a file
     * tree depending on the request.
     * 
     * @param StoreFileRequest request The `` parameter is an instance of the
     * `StoreFileRequest` class, which is a custom request class that handles the validation and
     * authorization logic for storing a file.
     */
    public function store(StoreFileRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;
        $user = $request->user();
        $fileTree = $request->file_tree;

        if (!$parent) {
            $parent = $this->getRoot();
        }

        if (!empty($fileTree)) {
            $this->saveFileTree($fileTree, $parent, $user);
        } else {
            foreach ($data['files'] as $file) {
                /** @var \Illuminate\Http\UploadedFile $file */

                $this->saveFile($file, $user, $parent);
            }
        }
    }

    /**
     * The function retrieves the root file for the currently authenticated user.
     * 
     * @return the first file that is marked as the root and was created by the currently authenticated
     * user.
     */
    private function getRoot()
    {
        return File::query()->whereIsRoot()->where('created_by', Auth::id())->firstOrFail();
    }

    /**
     * The function saves a file tree structure by recursively iterating through the tree and creating
     * folders and files in a database.
     * 
     * @param fileTree An array representing a file tree structure. Each key-value pair in the array
     * represents a file or a folder. If the value is an array, it represents a folder, and if it is a
     * string, it represents a file.
     * @param parent The "parent" parameter represents the parent folder in which the file or folder
     * will be saved.
     * @param user The user parameter represents the user who is saving the file tree.
     */
    public function saveFileTree($fileTree, $parent, $user)
    {
        foreach ($fileTree as $name => $file) {
            if (is_array($file)) {
                $folder = new File();
                $folder->is_folder = 1;
                $folder->name = $name;

                $parent->appendNode($folder);
                $this->saveFileTree($file, $folder, $user);
            } else {

                $this->saveFile($file, $user, $parent);
            }
        }
    }

    /**
     * The `destroy` function in PHP takes a request object, validates the data, and moves files to the
     * trash based on the provided IDs or all children of a parent file.
     * 
     * @param FilesActionRequest request The  parameter is an instance of the
     * FilesActionRequest class. It is used to retrieve and validate the data sent in the request.
     * 
     * @return a route to the 'myFiles' page with the folder parameter set to the path of the parent
     * folder.
     */
    public function destroy(FilesActionRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        if ($data['all']) {
            $children = $parent->children;

            foreach ($children as $child) {
                $child->moveToTrash();
            }
        } else {
            foreach ($data['ids'] ?? [] as $id) {
                $file = File::find($id);
                if ($file) {
                    $file->moveToTrash();
                }
            }
        }

        return to_route('myFiles', ['folder' => $parent->path]);
    }

    /**
     * The `download` function in PHP takes a request object, validates the data, and then either
     * creates a zip file of all files in a parent directory or retrieves the download URL and filename
     * for selected files.
     * 
     * @param FilesActionRequest request The request parameter is an instance of the FilesActionRequest
     * class. It is used to retrieve the validated data from the request.
     * 
     * @return an array with two keys: 'url' and 'filename'. The 'url' key contains the URL of the
     * downloaded file, and the 'filename' key contains the name of the downloaded file.
     */
    public function download(FilesActionRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        if ($all) {
            $url = $this->createZip($parent->children);
            $filename = $parent->name . '.zip';
        } else {
            [$url, $filename] = $this->getDownloadUrl($ids, $parent->name);
        }

        return [
            'url' => $url,
            'filename' => $filename
        ];
    }

    /**
     *
     *
     * @param $file
     * @param $user
     * @param $parent
     * @author Zura Sekhniashvili <zurasekhniashvili@gmail.com>
     */
    private function saveFile($file, $user, $parent): void
    {
        $path = $file->store('/files/' . $user->id, 'local');

        $model = new File();
        $model->storage_path = $path;
        $model->is_folder = false;
        $model->name = $file->getClientOriginalName();
        $model->mime = $file->getMimeType();
        $model->size = $file->getSize();
        $model->uploaded_on_cloud = 0;

        $parent->appendNode($model);

        UploadFileToCloudJob::dispatch($model);
    }

    /**
     * The function creates a zip file containing the specified files and returns the public URL of the
     * zip file.
     * 
     * @param files The parameter `` is an array of file paths that you want to include in the
     * zip archive.
     * 
     * @return string the asset URL of the created zip file.
     */
    public function createZip($files): string
    {
        $zipPath = 'zip/' . Str::random() . '.zip';
        $publicPath = "$zipPath";

        if (!is_dir(dirname($publicPath))) {
            Storage::disk('public')->makeDirectory(dirname($publicPath));
        }

        $zipFile = Storage::disk('public')->path($publicPath);

        $zip = new \ZipArchive();

        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $this->addFilesToZip($zip, $files);
        }

        $zip->close();

        return asset(Storage::disk('local')->url($zipPath));
    }

    /**
     * The function recursively adds files and folders to a zip archive.
     * 
     * @param zip The  parameter is the instance of the ZipArchive class that is used to create and
     * manipulate the zip file.
     * @param files An array of file objects. Each file object has properties like "is_folder"
     * (boolean), "children" (array of file objects), "name" (string), "storage_path" (string), and
     * "uploaded_on_cloud" (boolean).
     * @param ancestors The "ancestors" parameter is a string that represents the path of the parent
     * folders of the current file being added to the zip. It is used to maintain the folder structure
     * within the zip file.
     */
    private function addFilesToZip($zip, $files, $ancestors = '')
    {
        foreach ($files as $file) {
            if ($file->is_folder) {
                $this->addFilesToZip($zip, $file->children, $ancestors . $file->name . '/');
            } else {
                $localPath = Storage::disk('local')->path($file->storage_path);
                if ($file->uploaded_on_cloud == 1) {
                    $dest = pathinfo($file->storage_path, PATHINFO_BASENAME);
                    $content = Storage::get($file->storage_path);
                    Storage::disk('public')->put($dest, $content);
                    $localPath = Storage::disk('public')->path($dest);
                }

                $zip->addFile($localPath, $ancestors . $file->name);
            }
        }
    }

    /**
     * The `restore` function restores either all trashed files or specific files based on the provided
     * request data and returns the route to the trash page.
     * 
     * @param TrashFilesRequest request The  parameter is an instance of the TrashFilesRequest
     * class. It is used to retrieve the data sent in the request, such as the 'all' flag and the 'ids'
     * array. The TrashFilesRequest class is likely a custom request class that extends the base
     * Laravel Request class and contains
     * 
     * @return the result of the `to_route('trash')` function call.
     */
    public function restore(TrashFilesRequest $request)
    {
        $data = $request->validated();
        if ($data['all']) {
            $children = File::onlyTrashed()->get();
            foreach ($children as $child) {
                $child->restore();
            }
        } else {
            $ids = $data['ids'] ?? [];
            $children = File::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($children as $child) {
                $child->restore();
            }
        }

        return to_route('trash');
    }

    /**
     * The function deletes files permanently from the trash based on the provided request data.
     * 
     * @param TrashFilesRequest request The  parameter is an instance of the TrashFilesRequest
     * class. It is used to retrieve and validate the data sent in the request.
     * 
     * @return the result of the `to_route('trash')` function call.
     */
    public function deleteForever(TrashFilesRequest $request)
    {
        $data = $request->validated();
        if ($data['all']) {
            $children = File::onlyTrashed()->get();
            foreach ($children as $child) {
                $child->deleteForever();
            }
        } else {
            $ids = $data['ids'] ?? [];
            $children = File::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($children as $child) {
                $child->deleteForever();
            }
        }

        return to_route('trash');
    }

    /**
     * The function addToFavourites allows a user to add or remove a file from their list of favorites.
     * 
     * @param AddToFavouritesRequest request The  parameter is an instance of the
     * AddToFavouritesRequest class. It is used to validate and retrieve the data sent in the request.
     * 
     * @return a redirect back to the previous page.
     */
    public function addToFavourites(AddToFavouritesRequest $request)
    {
        $data = $request->validated();

        $id = $data['id'];
        $file = File::find($id);
        $user_id = Auth::id();

        $starredFile = StarredFile::query()
            ->where('file_id', $file->id)
            ->where('user_id', $user_id)
            ->first();

        if ($starredFile) {
            $starredFile->delete();
        } else {
            StarredFile::create([
                'file_id' => $file->id,
                'user_id' => $user_id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return redirect()->back();
    }

    /**
     * The `share` function in PHP validates a request to share files, retrieves the necessary data,
     * checks if the user exists, retrieves the files to be shared, checks if the files are already
     * shared with the user, inserts new file shares if necessary, sends an email notification to the
     * user, and redirects back to the previous page.
     * 
     * @param ShareFilesRequest request The  parameter is an instance of the ShareFilesRequest
     * class, which is used to validate and retrieve the data sent in the request.
     * 
     * @return a redirect back to the previous page.
     */
    public function share(ShareFilesRequest $request)
    {
        $data = $request->validated();
        $parent = $request->parent;

        $all = $data['all'] ?? false;
        $email = $data['email'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to share'
            ];
        }

        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            return redirect()->back();
        }

        if ($all) {
            $files = $parent->children;
        } else {
            $files = File::find($ids);
        }

        $data = [];
        $ids = Arr::pluck($files, 'id');
        $existingFileIds = FileShare::query()
            ->whereIn('file_id', $ids)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('file_id');

        foreach ($files as $file) {
            if ($existingFileIds->has($file->id)) {
                continue;
            }
            $data[] = [
                'file_id' => $file->id,
                'user_id' => $user->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }
        FileShare::insert($data);

        Mail::to($user)->send(new ShareFilesMail($user, Auth::user(), $files));

        return redirect()->back();
    }

    /**
     * The function `downloadSharedWithMe` allows users to download files that have been shared with
     * them, either individually or as a zip file.
     * 
     * @param FilesActionRequest request The  parameter is an instance of the
     * FilesActionRequest class. It is used to validate and retrieve the data sent in the request.
     * 
     * @return an array with two keys: 'url' and 'filename'. The 'url' key contains the URL of the file
     * to be downloaded, and the 'filename' key contains the name of the file.
     */
    public function downloadSharedWithMe(FilesActionRequest $request)
    {
        $data = $request->validated();

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        $zipName = 'shared_with_me';
        if ($all) {
            $files = File::getSharedWithMe()->get();
            $url = $this->createZip($files);
            $filename = $zipName . '.zip';
        } else {
            [$url, $filename] = $this->getDownloadUrl($ids, $zipName);
        }

        return [
            'url' => $url,
            'filename' => $filename
        ];
    }

    /**
     * The function `downloadSharedByMe` is used to download files that are shared by the user.
     * 
     * @param FilesActionRequest request The  parameter is an instance of the
     * FilesActionRequest class. It is used to retrieve the validated data from the request.
     * 
     * @return an array with two keys: 'url' and 'filename'. The 'url' key contains the URL of the file
     * to be downloaded, and the 'filename' key contains the name of the file.
     */
    public function downloadSharedByMe(FilesActionRequest $request)
    {
        $data = $request->validated();

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (!$all && empty($ids)) {
            return [
                'message' => 'Please select files to download'
            ];
        }

        $zipName = 'shared_by_me';
        if ($all) {
            $files = File::getSharedByMe()->get();
            $url = $this->createZip($files);
            $filename = $zipName . '.zip';
        } else {
            [$url, $filename] = $this->getDownloadUrl($ids, $zipName);
        }

        return [
            'url' => $url,
            'filename' => $filename
        ];
    }

    /**
     * The function `getDownloadUrl` takes an array of file IDs and a zip name, and returns the
     * download URL and filename for the zip file.
     * 
     * @param array ids An array of file IDs. These IDs represent the files that need to be downloaded.
     * @param zipName The `zipName` parameter is a string that represents the desired name of the zip
     * file that will be created.
     * 
     * @return An array is being returned with two elements: the download URL and the filename.
     */
    private function getDownloadUrl(array $ids, $zipName)
    {
        if (count($ids) === 1) {
            $file = File::find($ids[0]);
            if ($file->is_folder) {
                if ($file->children->count() === 0) {
                    return [
                        'message' => 'The folder is empty'
                    ];
                }
                $url = $this->createZip($file->children);
                $filename = $file->name . '.zip';
            } else {
                $dest = pathinfo($file->storage_path, PATHINFO_BASENAME);
                if ($file->uploaded_on_cloud) {
                    $content = Storage::get($file->storage_path);
                } else {
                    $content = Storage::disk('local')->get($file->storage_path);
                }

                Log::debug("Getting file content. File:  " . $file->storage_path) . ". Content: " .  intval($content);

                $success = Storage::disk('public')->put($dest, $content);
                Log::debug('Inserted in public disk. "' . $dest . '". Success: ' . intval($success));
                $url = asset(Storage::disk('public')->url($dest));
                Log::debug("Logging URL " . $url);
                $filename = $file->name;
            }
        } else {
            $files = File::query()->whereIn('id', $ids)->get();
            $url = $this->createZip($files);

            $filename = $zipName . '.zip';
        }

        return [$url, $filename];
    }
}