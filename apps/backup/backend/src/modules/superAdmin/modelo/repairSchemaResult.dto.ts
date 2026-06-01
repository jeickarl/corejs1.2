import { ApiProperty } from '@nestjs/swagger';

export class RepairSchemaFailDto {
  @ApiProperty()
  step!: string;

  @ApiProperty()
  error!: string;
}

export class RepairSchemaTenantResultDto {
  @ApiProperty()
  tenantId!: number;

  @ApiProperty()
  companyName!: string;

  @ApiProperty()
  status!: string;

  @ApiProperty()
  ok!: number;

  @ApiProperty()
  fail!: number;

  @ApiProperty({ type: [RepairSchemaFailDto] })
  fails!: RepairSchemaFailDto[];
}

