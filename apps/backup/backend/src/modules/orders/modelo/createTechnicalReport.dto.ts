import { ApiProperty } from '@nestjs/swagger';

export class CreateTechnicalReportDto {
  @ApiProperty()
  reportTitle!: string;

  @ApiProperty({ required: false })
  diagnosis?: string;

  @ApiProperty({ required: false })
  procedureTaken?: string;

  @ApiProperty({ required: false })
  introduction?: string;

  @ApiProperty({ required: false })
  conclusion?: string;
}

