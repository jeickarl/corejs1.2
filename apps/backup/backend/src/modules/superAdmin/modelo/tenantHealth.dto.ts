import { ApiProperty } from '@nestjs/swagger';

export class TenantHealthDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  companyName!: string;

  @ApiProperty()
  status!: string;

  @ApiProperty()
  dbHost!: string;

  @ApiProperty()
  dbPort!: number;

  @ApiProperty()
  dbName!: string;

  @ApiProperty()
  dbUser!: string;

  @ApiProperty()
  ok!: boolean;

  @ApiProperty({ nullable: true })
  error!: string | null;
}

