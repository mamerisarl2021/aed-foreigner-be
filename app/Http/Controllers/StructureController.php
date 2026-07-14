<?php

namespace App\Http\Controllers;

use App\Http\Requests\Management\UpdateStructureStatusRequest;
use App\Services\Structure\StructureManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class StructureController extends BaseController
{
    public function __construct(
        private readonly StructureManagementService $structures,
    ) {}

    /**
     * @OA\Get(
     *      path="/api/v1/structures",
     *      operationId="getStructures",
     *      tags={"Structures"},
     *      summary="List Structures",
     *      description="Returns a paginated list of structures.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="perPage",
     *          in="query",
     *          description="Items per page",
     *          required=false,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *              @OA\Property(property="pagination", type="object")
     *          )
     *      ),
     *
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->structures->list(min((int) $request->get('perPage', 15), 100))
        );
    }

    /**
     * @OA\Get(
     *      path="/api/v1/entities/mine",
     *      operationId="getMyStructures",
     *      tags={"Structures"},
     *      summary="Get My Structures",
     *      description="Returns a list of structures managed by the authenticated user.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *          )
     *      ),
     *
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function mine()
    {
        return $this->respond($this->structures->mine((int) auth()->id()));
    }

    public function search(Request $request): JsonResponse
    {
        $result = $this->structures->search((string) $request->input('query'));

        return $result->success
            ? response()->json($result->data)
            : response()->json(['error' => $result->message], $result->code);
    }

    /**
     * @OA\Get(
     *      path="/api/v1/structures/{id}",
     *      operationId="getStructure",
     *      tags={"Structures"},
     *      summary="Get Structure Details",
     *      description="Returns the details of a specific structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="id",
     *          in="path",
     *          description="ID of the structure",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=404, description="Not Found"),
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function show(Request $request, $id)
    {
        return $this->respond($this->structures->show((int) $id, $request->user()));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/clients/one-shot-link",
     *      operationId="createStructureOneShot",
     *      tags={"Structures"},
     *      summary="Create Structure (One Shot)",
     *      description="Creates a structure and uploads its attachments in a single request.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"name", "ifu"},
     *
     *                  @OA\Property(property="name", type="string", description="Name of the structure"),
     *                  @OA\Property(property="ifu", type="string", description="IFU of the structure"),
     *                  @OA\Property(
     *                      property="attachements",
     *                      type="array",
     *
     *                      @OA\Items(
     *                          type="object",
     *                          required={"name", "status", "files"},
     *
     *                          @OA\Property(property="name", type="string"),
     *                          @OA\Property(property="status", type="string", enum={"SENT","VALIDATED","WAITING_MANAGER","REJECTED"}),
     *                          @OA\Property(property="message", type="string", nullable=true),
     *                          @OA\Property(property="files[]", type="array", @OA\Items(type="string", format="binary"))
     *                      )
     *                  )
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Structure created successfully",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *
     *      @OA\Response(response=400, description="Validation Error or Attachment Failed"),
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function oneShotStore(Request $request)
    {
        return $this->respond($this->structures->oneShotStore($request, (int) $request->user()->id));
    }

    public function oneShotAttach(Request $request)
    {
        return $this->respond($this->structures->oneShotAttach($request));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/clients/link-entity",
     *      operationId="createStructure",
     *      tags={"Structures"},
     *      summary="Create Structure (Basic)",
     *      description="Creates a structure without attachments.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"name", "ifu"},
     *
     *              @OA\Property(property="name", type="string", example="Company LLC"),
     *              @OA\Property(property="ifu", type="string", example="1234567890")
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Structure created successfully",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'ifu' => 'required|string',
        ]);

        return $this->respond($this->structures->store($validatedData, (int) $request->user()->id));
    }

    /**
     * @OA\Put(
     *      path="/api/v1/structures/{id}",
     *      operationId="updateStructure",
     *      tags={"Structures"},
     *      summary="Update Structure",
     *      description="Updates a specific structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="name", type="string"),
     *              @OA\Property(property="status", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Updated successfully"),
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'string|max:255',
            'status' => 'string|max:255',
        ]);

        $result = $this->structures->update((int) $id, $validatedData);

        return $result->success
            ? response()->json(['message' => $result->message], 200)
            : response()->json(['error' => $result->message], $result->code);
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/structures/{id}",
     *      operationId="deleteStructure",
     *      tags={"Structures"},
     *      summary="Delete Structure",
     *      description="Deletes a specific structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(response=200, description="Deleted successfully"),
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function destroy($id): JsonResponse
    {
        $result = $this->structures->destroy((int) $id);

        return $result->success
            ? response()->json(['message' => $result->message], 200)
            : response()->json(['error' => $result->message], $result->code);
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/structures/update-status",
     *      operationId="updateStructureStatus",
     *      tags={"Structures"},
     *      summary="Update Structure Status (Bulk)",
     *      description="Updates status for multiple structures.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"structures"},
     *
     *              @OA\Property(
     *                  property="structures",
     *                  type="array",
     *
     *                  @OA\Items(
     *                      type="object",
     *                      required={"id", "status"},
     *
     *                      @OA\Property(property="id", type="integer"),
     *                      @OA\Property(property="status", type="string", enum={"APPROVED","REJECTED","PENDING"})
     *                  )
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Updated successfully"),
     *      @OA\Response(response=400, description="Validation Error"),
     *      @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function updateStructureStatus(UpdateStructureStatusRequest $request)
    {
        return $this->respond($this->structures->updateStructureStatus($request->input('structures')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/structures/notify-admin",
     *      operationId="sendStructureOtp",
     *      tags={"Structures"},
     *      summary="Send OTP for Structure Admin",
     *      description="Sends an OTP to the structure admin email.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "entity_id"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="entity_id", type="integer")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP sent successfully")
     * )
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'entity_id' => 'required|integer|exists:structures,id',
        ]);

        return $this->respond($this->structures->sendOtp($request->input('email')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/structures/verify-admin",
     *      operationId="verifyStructureOtp",
     *      tags={"Structures"},
     *      summary="Verify OTP for Structure Admin",
     *      description="Verifies the OTP and approves the structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "otp", "entity_id"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="otp", type="string"),
     *              @OA\Property(property="entity_id", type="integer")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Verified successfully"),
     *      @OA\Response(response=403, description="Invalid OTP")
     * )
     */
    public function verifyOtp(Request $request)
    {
        $validatedData = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string',
            'entity_id' => 'required|integer|exists:structures,id',
        ]);

        return $this->respond($this->structures->verifyOtp($validatedData));
    }

    /**
     * @OA\Get(
     *      path="/api/v1/structures/{structure}/employees",
     *      operationId="listEmployees",
     *      tags={"Structures"},
     *      summary="List employees of a structure",
     *      description="Returns a list of employees associated with a structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *          )
     *      ),
     *
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=404, description="Structure not found")
     * )
     */
    public function listEmployees(Request $request, $structureId)
    {
        return $this->respond($this->structures->listEmployees((int) $structureId, $request->user()));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/structures/{structure}/invite-employee",
     *      operationId="inviteEmployee",
     *      tags={"Structures"},
     *      summary="Invite an employee to join a structure",
     *      description="Sends an invitation to a user to join the structure as an employee.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"user_identifier"},
     *
     *              @OA\Property(
     *                  property="user_identifier",
     *                  type="string",
     *                  description="Email, NPI, or user ID"
     *              ),
     *              @OA\Property(
     *                  property="role",
     *                  type="string",
     *                  description="Role in the structure",
     *                  enum={"EMPLOYEE", "MANAGER_ASSISTANT", "VIEWER"},
     *                  default="EMPLOYEE"
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Invitation sent successfully"
     *      ),
     *      @OA\Response(response=400, description="Invalid request"),
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=404, description="User or structure not found")
     * )
     */
    public function inviteEmployee(Request $request, $structureId)
    {
        $validatedData = $request->validate([
            'user_identifier' => 'required|string',
            'role' => 'sometimes|string|in:EMPLOYEE,MANAGER_ASSISTANT,VIEWER',
            'message' => 'sometimes|string|max:500',
        ]);

        return $this->respond(
            $this->structures->inviteEmployee($validatedData, (int) $structureId, $request->user())
        );
    }

    /**
     * @OA\Post(
     *      path="/api/v1/structures/{structure}/add-employee",
     *      operationId="addEmployee",
     *      tags={"Structures"},
     *      summary="Add an employee directly to a structure",
     *      description="Adds a user directly as an employee to the structure (bypass invitation).",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"user_id"},
     *
     *              @OA\Property(property="user_id", type="integer"),
     *              @OA\Property(
     *                  property="role",
     *                  type="string",
     *                  enum={"EMPLOYEE", "MANAGER_ASSISTANT", "VIEWER"},
     *                  default="EMPLOYEE"
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Employee added successfully"
     *      ),
     *      @OA\Response(response=400, description="Invalid request"),
     *      @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function addEmployee(Request $request, $structureId)
    {
        $validatedData = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'sometimes|string|in:EMPLOYEE,MANAGER_ASSISTANT,VIEWER',
        ]);

        return $this->respond(
            $this->structures->addEmployee($validatedData, (int) $structureId, $request->user())
        );
    }

    /**
     * @OA\Put(
     *      path="/api/v1/structures/{structure}/employees/{user}",
     *      operationId="updateEmployeeRole",
     *      tags={"Structures"},
     *      summary="Update employee role in a structure",
     *      description="Updates the role of an employee in the structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Parameter(
     *          name="user",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"role"},
     *
     *              @OA\Property(
     *                  property="role",
     *                  type="string",
     *                  enum={"EMPLOYEE", "MANAGER_ASSISTANT", "VIEWER"}
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Role updated successfully"
     *      ),
     *      @OA\Response(response=400, description="Invalid request"),
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function updateEmployeeRole(Request $request, $structureId, $userId)
    {
        $validatedData = $request->validate([
            'role' => 'required|string|in:EMPLOYEE,MANAGER_ASSISTANT,VIEWER',
        ]);

        return $this->respond(
            $this->structures->updateEmployeeRole($validatedData, (int) $structureId, (int) $userId, $request->user())
        );
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/structures/{structure}/employees/{user}",
     *      operationId="removeEmployee",
     *      tags={"Structures"},
     *      summary="Remove an employee from a structure",
     *      description="Removes an employee from the structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Parameter(
     *          name="user",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Employee removed successfully"
     *      ),
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function removeEmployee(Request $request, $structureId, $userId)
    {
        return $this->respond(
            $this->structures->removeEmployee((int) $structureId, (int) $userId, $request->user())
        );
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/structures/{structure}/force-add-employee",
     *      operationId="forceAddEmployee",
     *      tags={"Management"},
     *      summary="Force add employee to structure (Agent only)",
     *      description="Agents can forcefully add an employee to a structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"user_id"},
     *
     *              @OA\Property(property="user_id", type="integer"),
     *              @OA\Property(
     *                  property="role",
     *                  type="string",
     *                  enum={"EMPLOYEE", "MANAGER_ASSISTANT", "VIEWER"},
     *                  default="EMPLOYEE"
     *              ),
     *              @OA\Property(
     *                  property="reason",
     *                  type="string",
     *                  description="Reason for force adding"
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Employee force added successfully"
     *      ),
     *      @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function forceAddEmployee(Request $request, $structureId)
    {
        // Cette méthode est similaire à addEmployee mais sans les vérifications de manager
        // Elle est réservée aux agents
        return $this->addEmployee($request, $structureId);
    }

    /**
     * @OA\Get(
     *      path="/api/v1/management/structures/{structure}/pending-invitations",
     *      operationId="listPendingInvitations",
     *      tags={"Management"},
     *      summary="List pending invitations for a structure",
     *      description="Returns a list of pending invitations for a structure (Agent only).",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="structure",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *          )
     *      ),
     *
     *      @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function listPendingInvitations($structureId)
    {
        return $this->respond($this->structures->listPendingInvitations((int) $structureId));
    }
}
