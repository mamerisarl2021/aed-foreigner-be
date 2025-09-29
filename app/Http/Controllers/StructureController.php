<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyAdminJob;
use App\Jobs\SendOTPJob;
use App\Models\Attachment;
use App\Models\OTP;
use Illuminate\Http\Request;
use App\Models\Structure;
use App\Models\User;
use App\Traits\AttachmentTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StructureController extends BaseController
{
    use AttachmentTrait;
    public function index(Request $request)
    {
        try {
            // Paginate the results with 10 items per page
            $structures = Structure::paginate($request->get('perPage', 9999999999999));

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $structures->toArray();
            $data = $flattenedData['data'];  // Extract the actual data
            unset($flattenedData['data']);   // Remove the nested data key

            // Merge the remaining pagination data with the actual data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            return $this->sendPaginatedResponse('Liste des structures.', $response);
        } catch (\Exception $e) {
            Log::error('Impossible de récupérer les structures: ' . $e->getMessage());
            return $this->sendError('Impossible de récupérer les structures.', null, 500);
        }
    }

    public function mine()
    {
        try {
            $structures = Structure::where('manager_id', auth()->id())->get();
            return $this->sendResponse('Mes entreprises.', $structures);
        } catch (\Exception $e) {
            Log::error('Fetching structures failed: ' . $e->getMessage());
            return $this->sendError('Fetching structures failed.', null, 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->input('query');
            $structures = Structure::where('name', 'LIKE', "%$query%")
                ->orWhere('ifu', 'LIKE', "%$query%")
                ->orWhere('searchbase', 'LIKE', "%$query%")
                ->where('status', '==', 'APPROVED')
                ->take(2)
                ->get();

            Log::debug($structures);

            return response()->json($structures);
        } catch (\Exception $e) {
            Log::error('Searching structures failed: ' . $e);
            return response()->json(['error' => 'Searching structures failed.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $structure = Structure::with(['manager', 'attachments.documents', 'userSubscriptions', 'structureSubscriptions'])->findOrFail($id);
            $user = User::find(auth()->id());

            if ($user->hasRole('client')) {
                if ($structure->manager_id !== $user->id) {
                    return $this->sendError('Vous n\'êtes pas autorisé à accéder à cette entreprise.', null, 403);
                }
                return $this->sendResponse('Entreprise récupérée avec succès.', $structure);
            } elseif ($user->hasAnyRole(['tech_one', 'tech_two', 'tech_three'])) {
                return $this->sendResponse('Entreprise récupérée avec succès.', $structure);
            } else {
                return $this->sendError('Vous n\'êtes pas autorisé à accéder à cette entreprise.', null, 403);
            }
        } catch (Exception $e) {
            Log::error('Impossible de récupérer cette entreprise: ' . $e->getMessage());
            return $this->sendError('Impossible de récupérer cette entreprise.', null, 500);
        }
    }

    public function oneShotStore(Request $request)
    {
        DB::beginTransaction();

        try {
            // Step 1: Validate Request Data
            $validatedData = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('structures')->where(function ($query) {
                        return $query->where('manager_id', Auth::id());
                    })
                ],
                'ifu' => 'required|string|unique:structures,ifu',
                'attachements.*.name' => 'required|string|max:255',
                // 'attachements.*.structure_id' => 'required|exists:structures,id',
                'attachements.*.status' => 'required|in:SENT,VALIDATED,WAITING_MANAGER,REJECTED',
                'attachements.*.message' => 'required_if:attachments.*.status,REJECTED|string',
                'attachements.*.files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:10000',
                // '' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:2048',
            ]);

            // Create the structure
            $structure = Structure::create([
                'name' => $validatedData['name'],
                'ifu' => $validatedData['ifu'],
                'manager_id' => Auth::id(),
                'status' => 'PENDING',
                'searchbase' => "ou=Employees-Virtual ID,ou={$validatedData['name']},o=GOUV,c=BJ",
            ]);

            foreach ($request->input('attachements') as $key => $attachmentData) {
                // Create each attachment
                $attachment = Attachment::create(array_merge($attachmentData, ['structure_id' => $structure->id]));

                // Handle file attachments for this attachment
                $files = $request->file("attachements.{$key}.files");

                if ($files) {
                    $response = $this->attachFiles($files, $attachment->id);

                    if (!$response['status']) {
                        DB::rollBack();
                        return $this->sendError(
                            'Les fichiers n\'ont pas pu être attachés à une ou plusieurs pièces jointes. Veuillez réessayer.',
                            null,
                            400
                        );
                    }
                }
                $finalFiles[] = $response['data'] ?? [];
            }


            // Commit the transaction
            DB::commit();

            // Return Success Response
            return $this->sendResponse(
                'Votre entité a été créée avec succès et les fichiers ont été attachés. Vous recevrez une notification lorsque l\'activation sera complète et lorsque le statut changera.',
                [
                    'structure' => $structure,
                    'files' => $finalFiles
                ]
            );
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la structure et de l\'attachement des fichiers : ' . $e->getMessage());
            return $this->sendError(
                $e->getMessage(),
                $e,
                500
            );
        }
    }

    public function oneShotAttach(object $request)
    {
        DB::beginTransaction();

        try {
            // Create Attachment
            $attachment = Attachment::create($request);

            // Handle file attachments for this attachment
            $files = $request->file('files');
            $response = $this->attachFiles($files, $attachment->id);

            if (!$response['status']) {
                DB::rollBack();
                return $this->sendError($response['message'], null, 400);
            }

            // Commit the transaction
            DB::commit();

            // Return Response
            return $this->sendResponse($response['message'], $response['data']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la pièce jointe : ' . $e->getMessage());
            return $this->sendError(
                'Une erreur est survenue pendant la création de la pièce jointe.',
                null,
                500
            );
        }
    }


    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'ifu' => 'required|string',
            ]);
            $validatedData["manager_id"] = Auth::user()->id;
            $validatedData["status"] = 'PENDING';
            $validatedData["searchbase"] = "ou=Employees-Virtual ID,ou=" . $validatedData['name'] . ",o=GOUV,c=BJ";
            Structure::create($validatedData);
            $structures = Structure::where('manager_id', auth()->id())->get();


            return $this->sendResponse('Votre entité à bien été créée. Il vous faudra renseigner les pièces nécessaires à son activation. Elle sera dès lors en attente de validation d\'un agent de la plateforme. Vous serez notifié dès que le statut de votre document changera.', $structures);
        } catch (\Exception $e) {
            Log::error('Creating structure failed: ' . $e->getMessage());
            return $this->sendError('Nous sommes dans le regret de vous annoncer que votre structure n\'a pas pu être créée et nous vous demandons de réessayer ultérieurement.', null, 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'string|max:255',
                'status' => 'string|max:255',
            ]);

            if($validatedData['status'] == 'APPROVED'){
                $validatedData['status'] = 'WAITING_MANAGER';
            }

            $structure = Structure::findOrFail($id);
            $structure->update($validatedData);

            return response()->json(['message' => 'Structure updated successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Updating structure failed: ' . $e->getMessage());
            return response()->json(['error' => 'Updating structure failed.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $structure = Structure::findOrFail($id);
            $structure->delete();
            return response()->json(['message' => 'Structure deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Deleting structure failed: ' . $e->getMessage());
            return response()->json(['error' => 'Deleting structure failed.'], 500);
        }
    }

    public function updateStructureStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'structures' => 'required|array',
            'structures.*.id' => 'required|integer|exists:structures,id',
            'structures.*.status' => 'required|in:APPROVED,REJECTED,PENDING',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $structures = $request->input('structures');

        try {
            foreach ($structures as $structureData) {
                $structure = Structure::findOrFail($structureData['id']);
                $status = $structureData['status'];

                if ($status == 'APPROVED') {
                    $status = 'WAITING_MANAGER';
                    $allAttachmentsValidated = Attachment::where('structure_id', $structure->id)
                        ->where('status', '!=', 'VALIDATED')
                        ->doesntExist();

                    if (!$allAttachmentsValidated) {
                        return $this->sendError("Impossible de valider la structure n° {$structure->id} car tous ses documents n'ont pas été validés.", null, 500);
                    }
                }

                $structure->update([
                    'status' => $status,
                ]);
            }
            return $this->sendResponse('Structures modifiées avec succès.', null, 200);
        } catch (\Exception $e) {
            Log::error('Failed to update structures status: ' . $e->getMessage());
            return $this->sendError("Nous n'avons pas pu mettre à jour le statut de l'une ou plusieurs des structures.", null, 500);
        }
    }

    public function sendOtp(Request $request)
    {
        // Validate that the email exists
        $request->validate([
            'email' => 'required|email',
            'entity_id' => 'required|integer|exists:structures,id'
        ]);

        // Retrieve the user by email
        $email = $request->input('email');

        // Generate a 6-digit OTP
        $otp = implode('', array_map(function () {
            return mt_rand(0, 9);
        }, range(1, 6)));

        // Set OTP validity to 5 minutes
        $validityMinutes = 5;
        $validUntil = Carbon::now()->addMinutes($validityMinutes);

        // Check if OTP already exists for this email
        $existingOTP = OTP::where('email', $email)->first();
        if ($existingOTP) {
            $existingOTP->update(['otp' => $otp, 'valid_until' => $validUntil]);
        } else {
            OTP::create([
                'email' => $email,
                'otp' => $otp,
                'valid_until' => $validUntil,
            ]);
        }

        // Dispatch job to send OTP via email
        NotifyAdminJob::dispatch($email, $otp);

        // Return success response
        return $this->sendResponse('OTP envoyé avec succès.', []);
    }

    public function verifyOtp(Request $request)
    {
        // Validate the input for email and OTP
        $validatedData = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string',
            'entity_id' => 'required|integer|exists:structures,id'
        ]);

        $email = $request->input('email');
        $otp = $request->input('otp');

        // Verify if the OTP exists and is still valid
        $existingOTP = OTP::where('email', $email)
            ->where('otp', $otp)
            ->where('valid_until', '>=', Carbon::now())
            ->first();

        if ($existingOTP) {
            // Optionally, you can delete the OTP from the database after verification
            $existingOTP->delete();
            $structure = Structure::findOrFail($validatedData['entity_id']);

            // Generate a token for the user
            $structure->update([
                'status' => 'APPROVED',
            ]);

            return $this->sendResponse("Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!", $structure);
        }

        return $this->sendError('OTP invalide ou expiré.', null, 403);
    }
}
